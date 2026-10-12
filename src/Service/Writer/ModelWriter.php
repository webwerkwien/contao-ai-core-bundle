<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Writer;

use Contao\Model;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\VirtualFields;
use Webwerkwien\ContaoAiCoreBundle\Service\RecordCascadeCollector;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Implementation A: writes through Contao's model layer, which is what every
 * command did inline before v0.2.16.
 *
 * Behaviour is deliberately unchanged from those inline versions — this was a
 * move, not a rewrite. What changed is that the rules now have one address and
 * can be tested without a console and a booted container.
 *
 * Implementation B would write through Contao's core operations once they exist;
 * see RecordWriterInterface for why that is not a today decision.
 */
class ModelWriter implements RecordWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VersionManager $versionManager,
        private readonly RecordCascadeCollector $cascadeCollector,
        private readonly ?CacheTagInvalidator $cacheTags = null,
        private readonly ?DcaCallbacks $callbacks = null,
    ) {
    }

    /** Fields a move writes; the back end changes them by cut and paste, not in the mask. */
    private const POSITION_FIELDS = ['pid', 'ptable', 'sorting'];

    public function update(string $table, int $id, array $fields, string $operator, bool $saveCallbacks = false): ?array
    {
        // All or nothing: a callback that refuses rolls the save back (v1.4.0).
        return $this->connection->transactional(fn (): ?array => $this->doUpdate($table, $id, $fields, $operator, $saveCallbacks));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>|null
     */
    private function doUpdate(string $table, int $id, array $fields, string $operator, bool $saveCallbacks = false): ?array
    {
        // On both paths: Model::save() drops a key that is no column without a word,
        // and the answer would name it as written (pre-release review M4). The
        // commands check beforehand; an extension through the public API may not.
        // A DCA field without a column may pass on to an onbeforesubmit_callback
        // (v1.5.0); what is still no column after it is refused in beforeSubmit().
        $this->refuseUnknownKeys($table, $fields);

        // A move: the old parent listed the record too (a navigation, an article
        // list). Its tags are read now, while the record still names it (v1.2.0).
        // Before findById(): tl_page's sitemap callback calls findWithDetails(),
        // which takes the loaded instance out of the registry, and a detached
        // instance cannot be saved (c5, 2026-10-09).
        $before = \array_key_exists('pid', $fields) || \array_key_exists('ptable', $fields)
            ? ($this->cacheTags?->collect($table, $id) ?? [])
            : [];

        // Contao 5 throws for a table without a registered model rather than guess one.
        $class = isset($GLOBALS['TL_MODELS'][$table]) ? Model::getClassFromTable($table) : null;

        // A table without a model — an extension's, through the public API (v1.4.0) —
        // is written row by row, as DC_Table writes every table.
        if (null === $class || !class_exists($class)) {
            $old = $this->connection->fetchAssociative('SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]);

            if (false === $old) {
                return null;
            }

            $fields = $this->withSaveCallbacks($saveCallbacks, $table, $id, $fields, $old);
            $fields = $this->beforeSubmit($table, $id, $fields, $old);

            $this->versionManager->initialize($table, $id);
            $this->connection->update($table, $this->quoted(['tstamp' => time()] + VirtualFields::combine($table, $fields, $old)), ['id' => $id]);
        } else {
            $record = $class::findById($id);

            if (null === $record) {
                return null;
            }

            $old    = $record->row();
            $fields = $this->withSaveCallbacks($saveCallbacks, $table, $id, $fields, $old);
            $fields = $this->beforeSubmit($table, $id, $fields, $old);

            // Virtual fields as they are: the model folds them into their target column
            // itself in 5.7 and 6.0, from its own values — folded here, save() rebuilt the
            // column from the old ones (c5, 6.0, 2026-10-12).
            foreach ($fields as $key => $value) {
                $record->$key = $value;
            }

            // As DC_Table::edit(): the state being replaced is kept if the record has
            // no version yet, the new state is versioned after the save (v1.4.0).
            $this->versionManager->initialize($table, $id);
            $record->tstamp = time();
            $record->save();
        }

        // The record callbacks the back end runs, in its order (v1.4.0): a new parent
        // is a cut (oncut), any other field a save of the mask (onsubmit) — both
        // before the new version, as in DC_Table.
        if ($this->moved($old, $fields)) {
            $this->callbacks?->cut($table, $id);
        }

        if ([] !== array_diff(array_keys($fields), self::POSITION_FIELDS)) {
            $this->callbacks?->submit($table, $id);
        }

        $this->versionManager->createVersion($table, $id, $operator);

        // After the write, as DC_Table::submit() does — before it, a request in
        // between would cache the old state again.
        $this->cacheTags?->recordChanged($table, $id);

        // Not empty only when there is an invalidator.
        if ([] !== $before) {
            $this->cacheTags->invalidate($before);
        }

        // What was written, including what an onbeforesubmit_callback added — but not
        // the tstamp it carries, which every write sets.
        return array_values(array_filter(array_map('strval', array_keys($fields)), static fn (string $field): bool => 'tstamp' !== $field));
    }

    public function create(string $table, array $fields, string $operator, bool $saveCallbacks = false): int
    {
        return $this->connection->transactional(function () use ($table, $fields, $operator, $saveCallbacks): int {
            $this->refuseUnknownKeys($table, $fields);

            // A DCA field without a column waits for the callbacks below; it cannot be
            // inserted (v1.5.0). A virtual field goes into its target column (v1.6.0).
            $columns  = $this->columns($table);
            $inserted = array_filter(VirtualFields::combine($table, $fields, []), static fn ($field): bool => \in_array(strtolower((string) $field), $columns, true), \ARRAY_FILTER_USE_KEY);

            $this->connection->insert($table, $this->quoted(['tstamp' => time()] + $inserted));
            $id = (int) $this->connection->lastInsertId();

            // As a create in the commands: oncreate, the fields' save_callbacks on the
            // record that now exists, onbeforesubmit, onsubmit, then the first version,
            // which is marked as the record's own (RecordIdReuseTest). DC_Table::create()
            // plus the saved mask.
            $this->callbacks?->create($table, $id);

            if (null !== $this->callbacks) {
                $row     = $this->connection->fetchAssociative('SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]) ?: [];
                $saved   = $this->withSaveCallbacks($saveCallbacks, $table, $id, $fields, $row);
                $saved   = $this->beforeSubmit($table, $id, $saved, $row);
                $changed = array_filter($saved, static fn ($value, $field): bool => !\array_key_exists($field, $inserted) || $inserted[$field] !== $value, \ARRAY_FILTER_USE_BOTH);

                if ([] !== $changed) {
                    $this->connection->update($table, $this->quoted(VirtualFields::combine($table, $changed, $row)), ['id' => $id]);
                }
            }

            $this->callbacks?->submit($table, $id);
            $this->versionManager->createInitialVersion($table, $id, $operator);
            $this->cacheTags?->recordChanged($table, $id);

            return $id;
        });
    }

    public function delete(string $table, int $id, string $operator, int $undoUserId): array
    {
        return $this->connection->transactional(fn (): array => $this->doDelete($table, $id, $undoUserId));
    }

    /**
     * The fields' save_callbacks when the caller asked for them — every field handed
     * over counts as set on purpose. Their return values are what gets written.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $row the stored record
     *
     * @return array<string, mixed>
     */
    private function withSaveCallbacks(bool $wanted, string $table, int $id, array $fields, array $row): array
    {
        if (!$wanted || null === $this->callbacks) {
            return $fields;
        }

        // A callback answers for its own field; it adds none (DcaCallbacks::save()
        // replaces values). Columns are checked after onbeforesubmit, in beforeSubmit().
        return $this->callbacks->save($table, $id, $fields, $row, [], array_map('strval', array_keys($fields)));
    }

    /**
     * The table's onbeforesubmit_callbacks, where DC_Table::submit() runs them: after
     * the save_callbacks, before the write, and not for a move (v1.5.0). They run on
     * every write, as onsubmit does — the record callbacks are the table's rules, not
     * an option of the caller; $saveCallbacks covers the fields' callbacks only.
     *
     * What is no column afterwards is refused by name: a DCA field without one that no
     * callback consumed would otherwise be dropped while the answer names it as written.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $row the stored record (for a create: as inserted)
     *
     * @return array<string, mixed>
     */
    private function beforeSubmit(string $table, int $id, array $fields, array $row): array
    {
        if (null !== $this->callbacks && [] !== array_diff(array_keys($fields), self::POSITION_FIELDS)) {
            $fields = $this->callbacks->beforeSubmit($table, $id, $fields, $row);
        }

        $unknown = $this->notColumns($table, $fields);

        if ([] !== $unknown) {
            $this->refuseMissing($table, $unknown, ' — no onbeforesubmit_callback took it over, so it would not be stored');
        }

        return $fields;
    }

    /**
     * Refuse keys that are no column, saying why where it can be told: a field the DCA
     * gives an `sql` definition is meant to be one — the table lacks it, most likely a
     * pending migration.
     *
     * @param list<string> $unknown
     */
    private function refuseMissing(string $table, array $unknown, string $otherwise): never
    {
        $declared = array_values(array_filter($unknown, static fn (string $field): bool => isset($GLOBALS['TL_DCA'][$table]['fields'][$field]['sql'])));

        throw new \InvalidArgumentException([] !== $declared
            ? \sprintf(
                'Not a column of %s: %s — the DCA defines a column for %s, the table does not have it; is a database migration pending (contao:migrate)? Nothing was written.',
                $table,
                implode(', ', $unknown),
                implode(', ', $declared),
            )
            : \sprintf('Not a column of %s: %s%s. Nothing was written.', $table, implode(', ', $unknown), $otherwise));
    }

    /**
     * A key that is no column would make the INSERT or UPDATE fail with an SQL error
     * that names the column but not the reason; this names both. A field the DCA
     * declares without a column passes when the table has onbeforesubmit_callbacks
     * to take it over — a virtual field, like a gross amount stored as net (v1.5.0).
     *
     * @param array<string, mixed> $fields
     */
    private function refuseUnknownKeys(string $table, array $fields): void
    {
        $unknown = $this->notColumns($table, $fields);

        if ([] !== $unknown && null !== $this->callbacks && [] !== ($GLOBALS['TL_DCA'][$table]['config']['onbeforesubmit_callback'] ?? [])) {
            $unknown = array_values(array_filter($unknown, static fn (string $field): bool => !isset($GLOBALS['TL_DCA'][$table]['fields'][$field])));
        }

        if ([] !== $unknown) {
            $this->refuseMissing($table, $unknown, '');
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    private function notColumns(string $table, array $fields): array
    {
        $columns = $this->columns($table);
        // A virtual field is stored in its target column (v1.6.0).
        $virtual = VirtualFields::map($table);

        return array_values(array_filter(
            array_map('strval', array_keys($fields)),
            static fn (string $field): bool => !\in_array(strtolower($field), $columns, true) && !isset($virtual[$field]),
        ));
    }

    /**
     * @return list<string> lower case
     */
    private function columns(string $table): array
    {
        return array_values(array_map(
            static fn ($column): string => strtolower($column->getName()),
            $this->connection->createSchemaManager()->listTableColumns($table),
        ));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function quoted(array $fields): array
    {
        $quoted = [];

        foreach ($fields as $field => $value) {
            $quoted[$this->connection->quoteIdentifier((string) $field)] = $value;
        }

        return $quoted;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $fields
     */
    private function moved(array $old, array $fields): bool
    {
        foreach (['pid', 'ptable'] as $key) {
            if (\array_key_exists($key, $fields) && (string) $fields[$key] !== (string) ($old[$key] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{cascade: array<string, int>, rowsTotal: int}
     */
    private function doDelete(string $table, int $id, int $undoUserId): array
    {
        // 🔴 Collected while the record still exists. DC_Table::delete() invalidates
        // BEFORE its DELETE loop (Contao 5.3, 5.7, 6.0): the sitemap callbacks look
        // the record up, and a page that is already gone has no root. Only the root
        // record, as in Contao — its children hang under its tag.
        $tags = $this->cacheTags?->collect($table, $id) ?? [];

        // See RecordCascadeCollector for what DC_Table::delete() does and why it
        // cannot be called from the console.
        $collected = $this->cascadeCollector->collect($table, $id);
        $rows      = $this->readRows($collected);

        // One undo entry for the whole set, in the shape DC_Table::undo() expects:
        // [table => [row, ...]].
        $undoId = $this->snapshotToUndo($table, $id, $rows, $undoUserId);

        // DC_Table::delete() calls ondelete for the record itself, not its children,
        // after the undo row and before the DELETE (v1.4.0). The search index entry of
        // a deleted page goes here, and Consho's facet page clean-up.
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]);

        if (\is_array($row)) {
            $this->callbacks?->delete($table, $id, $row, $undoId);
        }

        $rowsTotal = $this->deleteRows($collected);

        $this->cacheTags?->invalidate($tags);

        return [
            'cascade'   => array_map('\count', $collected),
            'rowsTotal' => $rowsTotal,
        ];
    }

    /**
     * @param array<string, list<int>> $collected
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function readRows(array $collected): array
    {
        $rows = [];

        foreach ($collected as $table => $ids) {
            foreach ($ids as $id) {
                $row = $this->connection->fetchAssociative(
                    'SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?',
                    [$id],
                );

                if (\is_array($row)) {
                    $rows[$table][] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Children first, so a half-failed run never leaves a parent pointing at rows
     * that are already gone.
     *
     * @param array<string, list<int>> $collected
     */
    private function deleteRows(array $collected): int
    {
        $affected = 0;

        foreach (array_reverse($collected, true) as $table => $ids) {
            foreach ($ids as $id) {
                $affected += (int) $this->connection->delete($table, ['id' => $id]);
            }
        }

        return $affected;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $rows
     */
    /**
     * @return int the ID of the undo row, 0 when there was nothing to keep
     */
    private function snapshotToUndo(string $table, int $id, array $rows, int $undoUserId): int
    {
        if (!$rows) {
            return 0;
        }

        $this->connection->insert('tl_undo', [
            'pid'          => $undoUserId,
            'tstamp'       => time(),
            'fromTable'    => $table,
            'query'        => \sprintf('DELETE FROM %s WHERE id=%d', $table, $id),
            'affectedRows' => array_sum(array_map('\count', $rows)),
            'data'         => serialize($rows),
        ]);

        return (int) $this->connection->lastInsertId();
    }
}
