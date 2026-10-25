<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\TableName;

/**
 * Put a deleted record back, the way the back end's "Restore" module does.
 *
 * `contao:version:restore` has existed for long and answers "this record
 * changed and I want it as it was". Its counterpart never existed: a record
 * that was *deleted* had a `tl_undo` row and no command able to use it. Every
 * delete in this bundle has been writing one since v0.2.8 — for a cascade, one
 * row covering the parent and everything under it — so the safety net was
 * being filled and never emptied.
 *
 * This follows `DC_Table::undo()` step for step, because the steps are not
 * decoration:
 *
 *  1. **`loadDataContainer($table)` before touching it** — an `onundo_callback`
 *     only exists once the DCA is loaded.
 *  2. **Drop columns the table no longer has.** Contao intersects the stored
 *     row against the live field list. An undo entry can outlive a migration,
 *     and inserting a column that was since removed would fail the whole
 *     restore over a field nobody misses.
 *  3. **Run `onundo_callback` per row**, right after its insert, with tl_undo's data
 *     container as `$dc` (DcaCallbacks::undo(), v1.8.1). How extensions rebuild what a
 *     delete tore down — search index entries, aliases, related records. Inside the
 *     transaction: a callback that throws rolls the restore back.
 *  4. **Delete the `tl_undo` row only if every insert succeeded.** A partial
 *     restore keeps its entry, so the rest is not lost along with it.
 *  5. **Log `Undone <query>`** to the same channel and with the same wording
 *     the back end uses, so both look alike in the system log.
 *
 * ⚠️ **Records come back with their original IDs**, which is what makes the
 * references in other tables valid again. If an ID has been taken since, the
 * insert fails and the entry survives untouched. `contao:undo:read` reports
 * that in advance rather than leaving it to be discovered here.
 */
#[AsCommand(name: 'contao:undo:restore', description: 'Restore deleted records from a tl_undo entry')]
class UndoRestoreCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'tl_undo entry ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $id  = (int) $this->input->getArgument('id');
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = ?', [$id]);

        if (false === $row) {
            return $this->outputError("Undo entry not found: $id");
        }

        $data = StringUtil::deserialize($row['data'] ?? null);

        if (!\is_array($data) || [] === $data) {
            return $this->outputError(\sprintf(
                'Undo entry %d carries no restorable payload. Contao abandons the restore '
                . 'in the same case and leaves the entry in place.',
                $id,
            ));
        }

        // 🔴 The restore used to run without a transaction.
        // If a row failed, the ones already inserted stayed AND the
        // undo entry stayed fully intact. On the next attempt exactly
        // these IDs collided — which made the entry unusable for good,
        // even for the rows that never arrived.
        //
        // The old code even reported the partial state honestly ("Rows
        // already written stay"). What was missing was not candour but the
        // conclusion: a restore that cannot be repeated is
        // worthless on the second attempt.
        //
        // 🎯 All or nothing. On an error the transaction rolls back,
        // the entry stays untouched, and a second attempt — after
        // fixing the cause — starts from the same conditions as the first.
        //
        // ⚠️ HERE WE DEVIATE FROM CONTAO, and on purpose.
        // `DC_Table::undo()` (Contao 5.7) inserts row by row, on a
        // failure only sets `$error = true`, carries on, and deletes the
        // tl_undo entry at the end only if nothing went wrong. No
        // transaction, no rollback — the same partial-state situation we are
        // fixing here.
        //
        // Why the deviation is still right: Contao's undo runs in the
        // back-end UI, where a human sees the result and can fix it up
        // by hand. This command is called by scripts and agents
        // that start a second attempt on `status: error` — and
        // after a partial restore that attempt is guaranteed to fail,
        // without anyone seeing why.
        //
        // The rule cuts both ways: first check
        // how Contao does it — and then decide whether its answer suits
        // our caller. Here it does not.
        //
        // Since v1.8.1 the `onundo_callback`s run INSIDE the transaction, each row's
        // right after its insert, like DC_Table::undo(). Up to v1.8.0 they ran
        // after the commit, with the command as
        // `$dc`, and their failure stayed a warning under an `ok`. What a
        // callback does outside the database a rollback does not bring back —
        // but half a restore reported as a whole one weighs
        // more; the undo entry is kept in that case.
        $restored  = [];
        $failed    = [];
        $pending   = [];

        foreach ($data as $table => $rows) {
            if (!\is_array($rows)) {
                continue;
            }

            // 🔴 Refused, not skipped (v1.9.2). Until then a key that failed the check
            // was passed over, and the transaction below deleted the entry anyway: an
            // `ok` with nothing restored, and the only copy gone. The check also
            // demanded `tl_`, so a deleted Catalog Manager entry (its tables never have
            // the prefix) met exactly that.
            if (!TableName::isValid((string) $table)) {
                $failed[(string) $table] = 'not a table name';
                continue;
            }

            $table   = (string) $table;
            $columns = $this->columnsOf($table);

            if ([] === $columns) {
                $failed[$table] = 'table no longer exists';
                continue;
            }

            Controller::loadDataContainer($table);

            foreach ($rows as $record) {
                if (!\is_array($record)) {
                    continue;
                }

                // Contao's own step: anything the table has since lost is dropped
                // rather than failing the insert. Compared lower-cased on both
                // sides because Doctrine lower-cases its column keys.
                $insert = [];
                $kept   = [];
                foreach ($record as $column => $value) {
                    if (\in_array(strtolower((string) $column), $columns, true)) {
                        $insert[$this->connection->quoteIdentifier((string) $column)] = $value;
                        $kept[(string) $column] = $value;
                    }
                }

                if ([] === $insert) {
                    $failed[$table] = 'no column of the stored row exists any more';
                    continue;
                }

                // $kept for onundo: the row as DC_Table::undo() hands it on, with the
                // column names as they are — not the quoted keys of the insert, under
                // which a callback reading $row['id'] found nothing.
                $pending[] = [$table, $insert, $record, $kept];
            }
        }

        if ([] !== $failed) {
            return $this->outputError(\sprintf(
                'Restore refused, undo entry %d untouched and still replayable: %s. Nothing was written.',
                $id,
                implode('; ', array_map(
                    static fn (string $t, string $why): string => "$t ($why)",
                    array_keys($failed),
                    $failed,
                )),
            ));
        }

        // One guard for every branch above that passes over a part of the payload
        // (v1.9.2): with nothing left to insert, the transaction below
        // would only delete the entry and answer `ok`. Contao keeps it in that case.
        if ([] === $pending) {
            return $this->outputError(\sprintf(
                'Restore refused, undo entry %d untouched and still replayable: its payload '
                . 'holds no row that could be written. Nothing was written.',
                $id,
            ));
        }

        // Which row was being written when it failed — otherwise the message
        // degrades to "ID ?" and the operator has to guess which one collided.
        $current = [];

        try {
            $this->connection->transactional(function () use ($pending, $id, $row, &$restored, &$current): void {
                foreach ($pending as [$table, $insert, $record, $kept]) {
                    $current = $record;
                    $this->connection->insert($this->connection->quoteIdentifier($table), $insert);
                    $restored[$table] = ($restored[$table] ?? 0) + 1;

                    // onundo per row right after its insert, as DC_Table::undo(), with
                    // tl_undo's data container — and inside the transaction: a callback that
                    // refuses rolls the whole restore back, the entry stays replayable (v1.8.1).
                    // Until v1.8.0 they ran after the commit with the command as `$dc`, and a
                    // failure was a warning under an `ok`.
                    $this->dcaCallbacks?->undo($table, $kept, $id, $row);
                }

                // Inside the transaction as well: either the rows are back and
                // the entry is gone, or neither happened.
                $this->connection->delete('tl_undo', ['id' => $id]);
            });
        } catch (\Throwable $e) {
            $restored = [];

            $refusal = $e instanceof \InvalidArgumentException || $e instanceof \Doctrine\DBAL\Exception;
            $message = \sprintf(
                'Restore rolled back, undo entry %d untouched and still replayable: %s%s',
                $id,
                // The defect's own class, which the wrapper below would otherwise hide.
                $refusal ? '' : (new \ReflectionClass($e))->getShortName() . ': ',
                $this->explainInsertFailure($e, $current),
            );

            // A refusal (a callback's InvalidArgumentException, a taken ID) is the
            // caller's to act on; anything else — a TypeError in a callback — goes on to
            // JsonErrorBoundary, which marks it a defect (since v1.8.1).
            if (!$refusal) {
                throw new \RuntimeException($message, 0, $e);
            }

            return $this->outputError($message);
        }

        // 🔴 HERE WE DO BETTER THAN THE BACK END, on purpose.
        // `DC_Table::undo()` calls `invalidateCacheTags()` on
        // itself — and its table at that moment is tl_undo. The
        // restored records are never invalidated, their
        // `oninvalidate_cache_tags_callback`s never run (Contao 5.3, 5.7, 6.0).
        // Seen with a restored Consho price: the
        // cached sitemap stayed without its product.
        //
        // Every restored row, after the commit and after onundo — so that
        // the callbacks see what the extensions rebuilt there.
        if (null !== $this->cacheTags) {
            $tags = [];

            foreach ($pending as [$table, , $record]) {
                if (isset($record['id'])) {
                    $tags = [...$tags, ...$this->cacheTags->collect($table, (int) $record['id'])];
                }
            }

            $this->cacheTags->invalidate($tags);

            foreach (self::listSourcesIn($pending) as $source) {
                $this->cacheTags->warn(\sprintf(
                    'A list of %s that was visited while it was deleted stays cached without it: '
                    . "Contao tags a list only with the containers it finds, so no tag reaches that page. "
                    . 'If the site may have been visited in between, run `cache clear`.',
                    $source,
                ));
            }
        }

        $this->logUndone((string) ($row['query'] ?? ''));

        $this->outputSuccess([
            'id'        => $id,
            'restored'  => $restored,
            'rowsTotal' => array_sum($restored),
            'query'     => $row['query'] ?? null,
        ]);

        return Command::SUCCESS;
    }

    /**
     * @return list<string> live column names, lower-cased; empty when the table is gone
     */
    private function columnsOf(string $table): array
    {
        try {
            return array_map(
                'strtolower',
                array_keys($this->connection->createSchemaManager()->listTableColumns($table)),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A reason a caller can act on, rather than a driver message.
     *
     * The overwhelmingly likely cause is a taken ID, and "Duplicate entry" does
     * not say what to do about it.
     *
     * @param array<string, mixed> $record
     */
    private function explainInsertFailure(\Throwable $e, array $record): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Duplicate entry') || str_contains($message, '1062')) {
            return \sprintf(
                'ID %s is taken again — a record cannot be restored over a live one',
                (string) ($record['id'] ?? '?'),
            );
        }

        return substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 300);
    }

    /**
     * Containers whose front end lists Contao tags only while they exist.
     *
     * `ModuleEventlist`, `ModuleNewsList`, `ModuleFaqList` and their twins drop a
     * container that is not there (`sortOutProtected()`) and return before
     * tagging the response with it. A page rendered while the calendar was
     * deleted therefore carries no `contao.db.tl_calendar.<id>`, and restoring
     * the calendar invalidates nothing that reaches it — measured: the event
     * list stayed empty for the cache lifetime (24 h there)
     * although every record was back. Restoring a single event is not affected:
     * its calendar was there all along and tagged the page.
     */
    private const LIST_SOURCES = [
        'tl_calendar'           => 'calendar',
        'tl_news_archive'       => 'news archive',
        'tl_faq_category'       => 'FAQ category',
        'tl_newsletter_channel' => 'newsletter channel',
    ];

    /**
     * @param list<array{0: string, 1: mixed, 2: array<string, mixed>, 3: array<string, mixed>}> $pending
     *
     * @return list<string> e.g. "calendar 12"
     */
    private static function listSourcesIn(array $pending): array
    {
        $found = [];

        foreach ($pending as [$table, , $record]) {
            if (isset(self::LIST_SOURCES[$table], $record['id'])) {
                $found[] = self::LIST_SOURCES[$table] . ' ' . (int) $record['id'];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The back end writes "Undone <query>" to the general channel. Same wording
     * and same channel here, so a restore from either side reads alike in the
     * system log — `outputSuccess()` adds this bundle's own audit entry beside it.
     */
    private function logUndone(string $query): void
    {
        if ('' === $query) {
            return;
        }

        try {
            System::getContainer()->get('monolog.logger.contao.general')->info('Undone ' . $query, $this->logContext(ContaoContext::GENERAL));
        } catch (\Throwable) {
            // No container in a bare test harness; the audit entry still happens.
        }
    }
}
