<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Writer;

use Contao\Model;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
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

    public function update(string $table, int $id, array $fields, string $operator): ?array
    {
        // All or nothing: a callback that refuses rolls the save back (v1.4.0).
        return $this->connection->transactional(fn (): ?array => $this->doUpdate($table, $id, $fields, $operator));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>|null
     */
    private function doUpdate(string $table, int $id, array $fields, string $operator): ?array
    {
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

            $this->refuseUnknownColumns($table, $fields);
            $this->versionManager->initialize($table, $id);
            $this->connection->update($table, $this->quoted(['tstamp' => time()] + $fields), ['id' => $id]);
        } else {
            $record = $class::findById($id);

            if (null === $record) {
                return null;
            }

            $old = $record->row();

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

        return array_keys($fields);
    }

    public function create(string $table, array $fields, string $operator): int
    {
        return $this->connection->transactional(function () use ($table, $fields, $operator): int {
            $this->refuseUnknownColumns($table, $fields);

            $this->connection->insert($table, $this->quoted(['tstamp' => time()] + $fields));
            $id = (int) $this->connection->lastInsertId();

            // As a create in the commands: oncreate and onsubmit before the first
            // version, which is marked as the record's own (RecordIdReuseTest).
            $this->callbacks?->create($table, $id);
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
     * A key that is no column would make the INSERT or UPDATE fail with an SQL error
     * that names the column but not the reason; this names both.
     *
     * @param array<string, mixed> $fields
     */
    private function refuseUnknownColumns(string $table, array $fields): void
    {
        $columns = array_map(
            static fn ($column): string => strtolower($column->getName()),
            $this->connection->createSchemaManager()->listTableColumns($table),
        );

        $unknown = array_values(array_filter(
            array_keys($fields),
            static fn ($field): bool => !\in_array(strtolower((string) $field), $columns, true),
        ));

        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Not a column of %s: %s. Nothing was written.', $table, implode(', ', $unknown)));
        }
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
