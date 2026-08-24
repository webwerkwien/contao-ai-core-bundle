<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Contao\Controller;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * Collects a record together with everything Contao would delete along with it.
 *
 * Contao has no foreign keys and no clean-up task for orphaned rows — the entire
 * cascade lives in DC_Table::delete(), which the console commands cannot use
 * (it calls denyAccessUnlessGranted() and there is no security token on the CLI).
 * Contao\Model::delete() on the other hand is a plain single-row DELETE, so using
 * it directly leaves articles and content elements behind: invisible in the back
 * end, still in the database, and unreachable by any clean-up Contao offers.
 *
 * This class therefore mirrors DC_Table::delete()'s collection step:
 *
 *   1. for tree tables (tl_page) the whole descendant subtree goes with the record,
 *   2. child tables from the DCA `ctable` are followed recursively,
 *   3. `dynamicPtable` children are matched on pid *and* ptable — tl_content hangs
 *      under articles, news and events, and nests inside itself,
 *   4. `doNotDeleteRecords` is honoured.
 *
 * The three points where it touches the environment — DCA, schema, database — are
 * protected so the traversal can be unit-tested without a Contao installation.
 */
class RecordCascadeCollector
{
    /**
     * Sanity bound for the descendant walk. A cycle is already excluded by the
     * seen-set; this only guards against pathological data, and it throws rather
     * than truncating — a delete that silently collects less than it should is
     * worse than one that refuses.
     */
    private const MAX_ITERATIONS = 1000;

    public function __construct(protected readonly Connection $connection)
    {
    }

    /**
     * @return array<string, list<int>> table => ids, root table first
     */
    public function collect(string $rootTable, int $rootId): array
    {
        $ids = [$rootId];

        // Tree tables have no ptable and nest inside themselves: deleting a page
        // takes its subpages with it, exactly as the back end does.
        if ($this->isTreeTable($rootTable)) {
            $ids = array_merge($ids, $this->descendants($rootTable, [$rootId]));
        }

        $collected = [$rootTable => $ids];
        $seen = [$rootTable => array_fill_keys($ids, true)];

        foreach ($ids as $id) {
            $this->collectChildren($rootTable, $id, $collected, $seen);
        }

        return $collected;
    }

    /**
     * @param array<string, list<int>>       $collected
     * @param array<string, array<int, true>> $seen
     */
    private function collectChildren(string $table, int $id, array &$collected, array &$seen): void
    {
        foreach ($this->childTables($table) as $childTable) {
            if ($this->doNotDeleteRecords($childTable)) {
                continue;
            }

            // A dynamicPtable child (tl_content) may hang under several parent
            // tables, so pid alone would sweep up unrelated records.
            $ptable = $this->isDynamicPtable($childTable) ? $table : null;

            foreach ($this->fetchIds($childTable, [$id], $ptable) as $childId) {
                if (isset($seen[$childTable][$childId])) {
                    continue;
                }

                $seen[$childTable][$childId] = true;
                $collected[$childTable][] = $childId;

                $this->collectChildren($childTable, $childId, $collected, $seen);
            }
        }
    }

    /**
     * All descendants of the given ids within the same table, breadth first.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function descendants(string $table, array $ids): array
    {
        $found = [];
        $seen = array_fill_keys($ids, true);
        $level = $ids;
        $iterations = 0;

        while ($level) {
            if (++$iterations > self::MAX_ITERATIONS) {
                throw new \RuntimeException(\sprintf(
                    'Descendant walk on "%s" exceeded %d iterations — aborting instead of '
                    . 'deleting an incomplete set.',
                    $table,
                    self::MAX_ITERATIONS
                ));
            }

            $next = [];

            foreach ($this->fetchIds($table, $level, null) as $id) {
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $next[] = $id;
                }
            }

            $found = array_merge($found, $next);
            $level = $next;
        }

        return $found;
    }

    protected function isTreeTable(string $table): bool
    {
        $dca = $this->dca($table);

        return empty($dca['config']['ptable'])
            && DataContainer::MODE_TREE === ($dca['list']['sorting']['mode'] ?? null)
            && $this->hasColumn($table, 'pid');
    }

    /**
     * @return list<string>
     */
    protected function childTables(string $table): array
    {
        $ctable = $this->dca($table)['config']['ctable'] ?? [];

        return \is_array($ctable) ? array_values($ctable) : [];
    }

    protected function isDynamicPtable(string $table): bool
    {
        return (bool) ($this->dca($table)['config']['dynamicPtable'] ?? false);
    }

    protected function doNotDeleteRecords(string $table): bool
    {
        return (bool) ($this->dca($table)['config']['doNotDeleteRecords'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dca(string $table): array
    {
        Controller::loadDataContainer($table);

        return $GLOBALS['TL_DCA'][$table] ?? [];
    }

    protected function hasColumn(string $table, string $column): bool
    {
        try {
            return isset($this->connection->createSchemaManager()->listTableColumns($table)[$column]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<int> $pids
     * @return list<int>
     */
    protected function fetchIds(string $table, array $pids, ?string $ptable): array
    {
        if (!$pids) {
            return [];
        }

        $sql = 'SELECT id FROM ' . $this->connection->quoteIdentifier($table)
            . ' WHERE pid IN (' . implode(',', array_map('\intval', $pids)) . ')';
        $params = [];

        if (null !== $ptable) {
            $sql .= ' AND ptable = ?';
            $params[] = $ptable;
        }

        return array_map('\intval', $this->connection->fetchFirstColumn($sql, $params));
    }
}
