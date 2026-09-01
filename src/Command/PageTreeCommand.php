<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * The page tree, built on the server, one level at a time.
 *
 * ## Why this is not `record:list`
 *
 * The CLI used to build the tree itself: `SELECT … FROM tl_page ORDER BY
 * sorting` over every page, then nest the rows in Python. That could not move
 * to `contao:record:list`, whose 100-row cap a real site passes easily —
 * wienerwandern.at has **283 pages**.
 *
 * 🎯 **But the cap was never the real problem.** Paginating around it with
 * `--offset` would work and still put **80 KB** of JSON in front of the caller,
 * for a question that is almost never "show me all 283 pages" but "what hangs
 * under this node". The size is the constraint, not the row count.
 *
 * ## How Contao answers it
 *
 * The back end tree never loads everything either. The expanded state lives per
 * node in the session, expanding goes through the `ptg` parameter, and one
 * level is rendered at a time. Where Contao does need a whole subtree — for
 * permissions, for the delete cascade — `Database::getChildRecords()` descends
 * recursively, `WHERE pid IN (…)` per level.
 *
 * Both are server-side. Contao never ships a pile of rows out to be assembled
 * elsewhere, and neither does this.
 *
 * ## The contract
 *
 *     contao:page:tree                  → all roots plus one level (depth 2)
 *     contao:page:tree --root=5         → page 5 and its subtree
 *     contao:page:tree --depth=99       → everything below the starting point
 *
 * `depth` counts levels of the returned tree, so the default of 2 means "the
 * top nodes and their children" — small enough to read, deep enough to navigate
 * from. Asking for more is allowed and explicit; the answer says how many nodes
 * came back, so a large one is never a surprise.
 *
 * `truncated` says whether pages exist below the cut. Without it a depth-limited
 * tree and a complete one look identical, and a caller would have no way to know
 * it is standing at an edge rather than a leaf.
 */
#[AsCommand(name: 'contao:page:tree', description: 'The page tree as nested JSON, one level at a time')]
class PageTreeCommand extends AbstractReadCommand
{
    private const TABLE         = 'tl_page';
    private const DEFAULT_DEPTH = 2;
    private const MAX_DEPTH     = 99;

    /** @var list<string> */
    private const DEFAULT_FIELDS = ['id', 'pid', 'title', 'alias', 'type', 'published'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Start at this page ID instead of the site roots', '');
        $this->addOption(
            'depth', null, InputOption::VALUE_REQUIRED,
            'Levels to return (1–'.self::MAX_DEPTH.'). 1 = the starting nodes only.',
            self::DEFAULT_DEPTH,
        );
        $this->addOption(
            'fields', null, InputOption::VALUE_REQUIRED,
            'Comma-separated columns per node. `id` and `pid` are always included.',
            implode(',', self::DEFAULT_FIELDS),
        );
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $depth = max(1, min(self::MAX_DEPTH, (int) $this->input->getOption('depth')));
        $root  = (string) $this->input->getOption('root');
        $rootId = '' === $root ? null : (int) $root;

        if (null !== $rootId && $rootId < 1) {
            return $this->outputError('--root must be a page ID');
        }

        $fields = $this->resolveFields((string) $this->input->getOption('fields'));
        if ([] === $fields) {
            return $this->outputError('fields: no known column left after filtering');
        }

        $top = null === $rootId
            ? $this->rowsByPid([0], $fields)
            : $this->rowsById([$rootId], $fields);

        if (null !== $rootId && [] === $top) {
            return $this->outputError('Page not found: '.$rootId);
        }

        [$tree, $count, $frontier] = $this->descend($top, $fields, $depth);

        $this->outputRecord([
            'root'      => $rootId,
            'depth'     => $depth,
            'nodes'     => $count,
            'truncated' => [] !== $frontier && $this->hasChildren($frontier),
            'tree'      => $tree,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Attach children level by level, the way `getChildRecords()` descends.
     *
     * Breadth-first on purpose: one query per level rather than one per node.
     * A 283-page tree is five queries, not 283.
     *
     * @param list<array<string, mixed>> $nodes
     * @param list<string>               $fields
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<int>} tree, node count, deepest ids
     */
    private function descend(array $nodes, array $fields, int $depth): array
    {
        $tree  = $nodes;
        $count = \count($nodes);
        $level = [];

        // References into $tree so a child can be appended where it belongs.
        $byId = [];
        foreach ($tree as $i => $node) {
            $tree[$i]['children'] = [];
            $byId[(int) $node['id']] = &$tree[$i];
            $level[] = (int) $node['id'];
        }

        for ($d = 1; $d < $depth && [] !== $level; ++$d) {
            $children = $this->rowsByPid($level, $fields);
            if ([] === $children) {
                return [$tree, $count, []];
            }

            $next    = [];
            $nextIds = [];

            foreach ($children as $child) {
                $child['children'] = [];
                $pid = (int) $child['pid'];

                if (!isset($byId[$pid])) {
                    continue;
                }

                $byId[$pid]['children'][] = $child;
                $key = array_key_last($byId[$pid]['children']);
                $next[(int) $child['id']] = &$byId[$pid]['children'][$key];
                $nextIds[] = (int) $child['id'];
                ++$count;
                unset($child);
            }

            $byId  = $next;
            $level = $nextIds;
            unset($next, $nextIds);
        }

        return [$tree, $count, $level];
    }

    /**
     * @param list<int>    $pids
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function rowsByPid(array $pids, array $fields): array
    {
        return $this->fetch('pid', $pids, $fields);
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function rowsById(array $ids, array $fields): array
    {
        return $this->fetch('id', $ids, $fields);
    }

    /**
     * @param list<int>    $values
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function fetch(string $column, array $values, array $fields): array
    {
        if ([] === $values) {
            return [];
        }

        $columnList = implode(', ', array_map(
            fn (string $f): string => $this->connection->quoteIdentifier($f),
            $fields,
        ));

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT %s FROM %s WHERE %s IN (?) ORDER BY sorting, id',
                $columnList,
                $this->connection->quoteIdentifier(self::TABLE),
                $this->connection->quoteIdentifier($column),
            ),
            [$values],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return array_map(
            fn (array $row): array => $this->convertFileTreeFieldsToUuid(self::TABLE, $row),
            $rows,
        );
    }

    /**
     * @param list<int> $pids
     */
    private function hasChildren(array $pids): bool
    {
        if ([] === $pids) {
            return false;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM '.$this->connection->quoteIdentifier(self::TABLE).' WHERE pid IN (?)',
            [$pids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        ) > 0;
    }

    /**
     * Requested columns, reduced to the ones the table actually has.
     *
     * `id` and `pid` are always included: without them the nesting cannot be
     * expressed, and a caller who omits them almost certainly did not mean to
     * ask for a tree that cannot say what hangs where.
     *
     * @return list<string>
     */
    private function resolveFields(string $raw): array
    {
        $requested = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $wanted    = array_values(array_unique(array_merge(['id', 'pid'], $requested)));

        $actual = array_map('strtolower', array_keys(
            $this->connection->createSchemaManager()->listTableColumns(self::TABLE),
        ));

        return array_values(array_filter(
            $wanted,
            static fn (string $f): bool => \in_array(strtolower($f), $actual, true),
        ));
    }
}
