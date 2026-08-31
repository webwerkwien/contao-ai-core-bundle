<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

/**
 * What a `tl_undo` entry would put back.
 *
 * `record:list tl_undo` can already show the row, but the part that matters is
 * a serialized blob: `data` holds `[table => [row, row, …]]`, and on a cascade
 * that is the parent plus everything under it. Reading it raw tells a caller
 * almost nothing, and restoring blind is not a reasonable alternative for an
 * operation that writes rows back into live tables.
 *
 * So this decodes the payload into a summary — which tables, how many rows,
 * which IDs — plus the two things that decide whether the restore can work at
 * all:
 *
 *  - **`idsTaken`**: whether a record with that ID exists again. Contao
 *    re-inserts with the original ID, so an occupied ID makes the insert fail.
 *  - **`droppedColumns`**: columns in the stored row that the table no longer
 *    has. `DC_Table::undo()` silently drops them (`array_intersect_key` against
 *    the live field list), which is the right behaviour after a migration but
 *    means the restored record can come back with less than it had.
 *
 * Neither is an error. Both are things you want to know before, not after.
 */
#[AsCommand(name: 'contao:undo:read', description: 'Show what a tl_undo entry would restore')]
class UndoReadCommand extends AbstractReadCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'tl_undo entry ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id  = (int) $this->input->getArgument('id');
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = ?', [$id]);

        if (false === $row) {
            return $this->outputError("Undo entry not found: $id");
        }

        $data = StringUtil::deserialize($row['data'] ?? null);

        if (!\is_array($data)) {
            return $this->outputError(\sprintf(
                'Undo entry %d carries no restorable payload — its `data` column does not '
                . 'deserialize to an array. Contao abandons the restore in the same case.',
                $id,
            ));
        }

        $tables = [];
        foreach ($data as $table => $rows) {
            if (!\is_array($rows)) {
                continue;
            }

            $columns = $this->columnsOf((string) $table);
            $ids     = [];
            $dropped = [];

            foreach ($rows as $record) {
                if (!\is_array($record)) {
                    continue;
                }
                if (isset($record['id'])) {
                    $ids[] = (int) $record['id'];
                }
                foreach (array_keys($record) as $column) {
                    if ([] !== $columns && !\in_array(strtolower((string) $column), $columns, true)) {
                        $dropped[] = (string) $column;
                    }
                }
            }

            $tables[(string) $table] = [
                'rows'           => \count($rows),
                'ids'            => $ids,
                'idsTaken'       => $this->takenIds((string) $table, $ids),
                'droppedColumns' => array_values(array_unique($dropped)),
            ];
        }

        $this->outputRecord([
            'id'        => $id,
            'fromTable' => $row['fromTable'] ?? null,
            'query'     => $row['query'] ?? null,
            'pid'       => isset($row['pid']) ? (int) $row['pid'] : null,
            'tstamp'    => isset($row['tstamp']) ? (int) $row['tstamp'] : null,
            'rowsTotal' => array_sum(array_column($tables, 'rows')),
            'tables'    => $tables,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Live column names, lower-cased.
     *
     * Doctrine's `listTableColumns()` lower-cases its array keys, so `singleSRC`
     * comes back as `singlesrc` — the trap documented on 2026-08-31. Comparing
     * lower-cased on both sides is what keeps a mixed-case column from being
     * reported as dropped when it is perfectly present.
     *
     * An empty result means the table itself is gone; the caller sees that as
     * an insert that will fail rather than as every column being dropped.
     *
     * @return list<string>
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
     * Of these IDs, the ones a record already occupies again.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function takenIds(string $table, array $ids): array
    {
        if ([] === $ids || !preg_match('/^tl_[a-z0-9_]+$/', $table)) {
            return [];
        }

        try {
            $found = $this->connection->fetchFirstColumn(
                'SELECT id FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id IN (?)',
                [$ids],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map('intval', $found);
    }
}
