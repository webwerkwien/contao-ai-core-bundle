<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoAiCoreBundle\Service\SensitiveFields;

/**
 * The rows a listing module shows, as JSON (v1.9.0).
 *
 * The listing bundle stores its query in the module: a table, a field list, a free SQL
 * condition (`list_where`) and a free sort (`list_sort`). contao-ai-cli ran that query
 * itself through doctrine:query:sql and cut Symfony's ASCII table back apart — the parser
 * that shifted every column at an umlaut in v0.3.3, the last place in the CLI that used it.
 *
 * This runs what ModuleListing runs, server-side: the condition and sort come from the
 * module, as the site configured them; the caller passes nothing but the id and paging.
 * What the front end does not show, the answer does not either: password fields and every
 * other sensitive field are masked (SensitiveFields), as in every read command since
 * v1.8.1. The front end's own search and `order_by` parameters are left out — they answer
 * a visitor's question, not the module's configuration.
 */
#[AsCommand(name: 'contao:listing:data', description: 'Return the rows a listing module shows, as JSON')]
class ListingDataCommand extends AbstractReadCommand
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 1000;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        #[Autowire(service: 'contao.insert_tag.parser')]
        private readonly ?InsertTagParser $insertTags = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'tl_module ID of a listing module');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows (1–' . self::MAX_LIMIT . ')', (string) self::DEFAULT_LIMIT);
        $this->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Rows to skip', '0');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id     = (int) $this->input->getArgument('id');
        $limit  = max(1, min(self::MAX_LIMIT, (int) $this->input->getOption('limit')));
        $offset = max(0, (int) $this->input->getOption('offset'));

        $module = $this->connection->fetchAssociative('SELECT * FROM tl_module WHERE id = ?', [$id]);
        if (false === $module) {
            return $this->outputError("Module not found: $id");
        }
        if ('listing' !== $module['type']) {
            return $this->outputError("Module $id is not a listing module (type: {$module['type']})");
        }

        $table  = trim((string) ($module['list_table'] ?? ''));
        $fields = StringUtil::trimsplit(',', (string) ($module['list_fields'] ?? ''));
        $fields = array_values(array_filter($fields, static fn (string $f): bool => '' !== $f));
        if ('' === $table || [] === $fields) {
            return $this->outputError("Module $id has no list_table or list_fields configured");
        }

        // The module puts the table name into its SQL unquoted; a name that is not one is
        // not run here.
        foreach ([$table, ...$fields] as $name) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                return $this->outputError(\sprintf('Module %d configures "%s", which is not a table or field name.', $id, $name));
            }
        }

        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);

        // As ModuleListing: insert tags in the condition are replaced first.
        $where = (string) ($module['list_where'] ?? '');
        if ('' !== $where && null !== $this->insertTags) {
            $where = $this->insertTags->replaceInline($where);
        }
        if (null !== ($refusal = $this->guessingRefusal($id, $table, $where, (string) ($module['list_sort'] ?? '')))) {
            return $this->outputError($refusal);
        }

        $whereSql = '' !== trim($where) ? ' WHERE (' . $where . ')' : '';
        $from     = ' FROM ' . $this->connection->quoteIdentifier($table) . ' t1';

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*)' . $from . $whereSql);

        $columns = array_values(array_unique(['id', ...$fields]));
        $select  = implode(', ', array_map(fn (string $c): string => 't1.' . $this->connection->quoteIdentifier($c), $columns));
        $sql     = 'SELECT ' . $select . $from . $whereSql . $this->orderBy($table, (string) ($module['list_sort'] ?? ''))
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = array_map(
            fn (array $row): array => SensitiveFields::maskTable($table, $this->convertFileTreeFieldsToUuid($table, $row)),
            $this->connection->fetchAllAssociative($sql),
        );

        $this->outputRecord([
            'module'  => $id,
            'table'   => $table,
            'count'   => \count($rows),
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'columns' => $columns,
            'results' => $rows,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Why the module's condition or sort is not run here, or null (v1.14.0).
     *
     * The output masks sensitive fields, but the condition runs before the mask: with
     * `list_where = "password LIKE '$2y$13$a%'"`, or a subquery into another table, the
     * row count answers yes or no, and `module update` lets a caller set the condition —
     * a secret read character by character. Refused are a condition or sort naming a
     * sensitive column of the listed table, and any subquery. The front end still runs
     * the module as configured; only this read command does not.
     */
    private function guessingRefusal(int $id, string $table, string $where, string $sort): ?string
    {
        $sql = $where . ' ' . $sort;

        // A subquery needs SELECT, or since MySQL 8.0.19 TABLE or VALUES. Words inside
        // string literals do not count (`type = 'select'`), with the literals read both
        // with and without backslash escapes, so neither reading can hide one. A
        // versioned comment (`/*!50000SELECT…*/`) has no word boundary to find.
        $outsideLiterals = [
            preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"/s', "''", $sql),
            preg_replace('/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"/s', "''", $sql),
        ];
        foreach ($outsideLiterals as $code) {
            if (null === $code || 1 === preg_match('/\b(?:select|table|values)\b|\/\*!/i', $code)) {
                return \sprintf('Module %d has a subquery in its condition or sort; contao:listing:data does not run it, a subquery could test values the output masks.', $id);
            }
        }

        foreach (SensitiveFields::columns($table) as $column) {
            if (1 === preg_match('/\b' . preg_quote($column, '/') . '\b/i', $sql)) {
                return \sprintf('Module %d filters or sorts by "%s", whose value is masked; contao:listing:data does not run that condition.', $id, $column);
            }
        }

        return null;
    }

    /**
     * ModuleListing's sort: `list_sort` as configured, cast to a number when it names a
     * date or time field (they are stored as timestamps in a string column).
     */
    private function orderBy(string $table, string $sort): string
    {
        $sort = trim($sort);
        if ('' === $sort) {
            return '';
        }

        $rgxp = $GLOBALS['TL_DCA'][$table]['fields'][$sort]['eval']['rgxp'] ?? null;

        return \in_array($rgxp, ['date', 'time', 'datim'], true)
            ? ' ORDER BY CAST(' . $this->connection->quoteIdentifier($sort) . ' AS SIGNED)'
            : ' ORDER BY ' . $sort;
    }
}
