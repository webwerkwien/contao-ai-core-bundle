<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Writer;

use Contao\Model;
use Doctrine\DBAL\Connection;
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
    ) {
    }

    public function update(string $table, int $id, array $fields, string $operator): ?array
    {
        $class  = Model::getClassFromTable($table);
        $record = $class::findById($id);

        if (null === $record) {
            return null;
        }

        foreach ($fields as $key => $value) {
            $record->$key = $value;
        }

        // Snapshot first: the version has to hold the state being replaced.
        $this->versionManager->createVersion($table, $id, $operator);
        $record->tstamp = time();
        $record->save();

        return array_keys($fields);
    }

    public function delete(string $table, int $id, string $operator, int $undoUserId): array
    {
        // See RecordCascadeCollector for what DC_Table::delete() does and why it
        // cannot be called from the console.
        $collected = $this->cascadeCollector->collect($table, $id);
        $rows      = $this->readRows($collected);

        // One undo entry for the whole set, in the shape DC_Table::undo() expects:
        // [table => [row, ...]].
        $this->snapshotToUndo($table, $id, $rows, $undoUserId);

        return [
            'cascade'   => array_map('\count', $collected),
            'rowsTotal' => $this->deleteRows($collected),
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
    private function snapshotToUndo(string $table, int $id, array $rows, int $undoUserId): void
    {
        if (!$rows) {
            return;
        }

        $this->connection->insert('tl_undo', [
            'pid'          => $undoUserId,
            'tstamp'       => time(),
            'fromTable'    => $table,
            'query'        => \sprintf('DELETE FROM %s WHERE id=%d', $table, $id),
            'affectedRows' => array_sum(array_map('\count', $rows)),
            'data'         => serialize($rows),
        ]);
    }
}
