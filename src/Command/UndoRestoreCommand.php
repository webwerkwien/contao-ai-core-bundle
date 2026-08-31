<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Put a deleted record back, the way the back end's "Restore" module does.
 *
 * `contao:version:restore` has existed since Phase 2 and answers "this record
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
 *  3. **Run `onundo_callback` per row.** How extensions rebuild what a delete
 *     tore down — search index entries, aliases, related records.
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

        $restored = [];
        $failed   = [];

        foreach ($data as $table => $rows) {
            if (!\is_array($rows) || !preg_match('/^tl_[a-z0-9_]+$/', (string) $table)) {
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
                foreach ($record as $column => $value) {
                    if (\in_array(strtolower((string) $column), $columns, true)) {
                        $insert[$this->connection->quoteIdentifier((string) $column)] = $value;
                    }
                }

                if ([] === $insert) {
                    $failed[$table] = 'no column of the stored row exists any more';
                    continue;
                }

                try {
                    $this->connection->insert($this->connection->quoteIdentifier($table), $insert);
                } catch (\Throwable $e) {
                    $failed[$table] = $this->explainInsertFailure($e, $record);
                    continue;
                }

                $restored[$table] = ($restored[$table] ?? 0) + 1;
                $this->runUndoCallbacks($table, $record);
            }
        }

        if ([] !== $failed) {
            return $this->outputError(\sprintf(
                'Restore incomplete, undo entry %d kept: %s. %s',
                $id,
                implode('; ', array_map(
                    static fn (string $t, string $why): string => "$t ($why)",
                    array_keys($failed),
                    $failed,
                )),
                [] === $restored
                    ? 'Nothing was written.'
                    : 'Rows already written stay: ' . json_encode($restored),
            ));
        }

        // Only now, and only because every insert succeeded. Contao deletes the
        // entry at the same point and for the same reason.
        $this->connection->delete('tl_undo', ['id' => $id]);

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
     * `onundo_callback` is how an extension rebuilds what a delete tore down.
     *
     * Skipping it would leave a record that looks restored and is missing its
     * search index entry or its related rows — the silent-success shape this
     * project keeps running into.
     *
     * @param array<string, mixed> $record
     */
    private function runUndoCallbacks(string $table, array $record): void
    {
        $callbacks = $GLOBALS['TL_DCA'][$table]['config']['onundo_callback'] ?? null;

        if (!\is_array($callbacks)) {
            return;
        }

        foreach ($callbacks as $callback) {
            try {
                if (\is_array($callback)) {
                    System::importStatic($callback[0])->{$callback[1]}($table, $record, $this);
                } elseif (\is_callable($callback)) {
                    $callback($table, $record, $this);
                }
            } catch (\Throwable $e) {
                // The row is already back. A failing callback is worth reporting,
                // but rolling the restore back over it would be worse.
                $this->logger->warning('contao-ai-core-bundle onundo_callback failed', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
            System::getContainer()->get('monolog.logger.contao.general')->info('Undone ' . $query);
        } catch (\Throwable) {
            // No container in a bare test harness; the audit entry still happens.
        }
    }
}
