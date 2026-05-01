<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:record:list', description: 'List records from a Contao table with filter, order and pagination')]
class RecordListCommand extends AbstractReadCommand
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('table', InputArgument::REQUIRED, 'Table name, e.g. tl_news');
        $this->addOption('limit',  null, InputOption::VALUE_REQUIRED, 'Max rows (1–'.self::MAX_LIMIT.')', self::DEFAULT_LIMIT);
        $this->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Offset', 0);
        $this->addOption('order',  null, InputOption::VALUE_REQUIRED, 'ORDER BY clause, e.g. "tstamp DESC"', 'id DESC');
        $this->addOption(
            'filter', null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Field=value equality filter (repeatable), e.g. --filter pid=5 --filter published=1',
            []
        );
        $this->addOption(
            'fields', null,
            InputOption::VALUE_REQUIRED,
            'Comma-separated columns to return. Empty = curated default per table.',
            ''
        );
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $table  = (string) $this->input->getArgument('table');
        $limit  = max(1, min(self::MAX_LIMIT, (int) $this->input->getOption('limit')));
        $offset = max(0, (int) $this->input->getOption('offset'));

        if (!$this->isValidTableName($table)) {
            return $this->outputError("Invalid table name: $table");
        }

        Controller::loadDataContainer($table);
        $dcaFields = array_keys($GLOBALS['TL_DCA'][$table]['fields'] ?? []);
        if ([] === $dcaFields) {
            return $this->outputError("DCA not found or empty for table: $table");
        }
        // `id` and `tstamp` are not always declared in DCA fields but always exist in tl_* tables.
        $allowedColumns = array_unique(array_merge(['id', 'tstamp'], $dcaFields));

        try {
            $orderClause = $this->buildOrderClause((string) $this->input->getOption('order'), $allowedColumns);
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        $rawFilters = (array) $this->input->getOption('filter');
        try {
            [$where, $params, $types] = $this->buildWhere($rawFilters, $allowedColumns);
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        $columns = $this->resolveColumns(
            (string) $this->input->getOption('fields'),
            $allowedColumns,
            $table,
        );

        // Doctrine quoting for SELECT-Liste; whitelist already validated.
        $columnList = implode(', ', array_map(
            fn (string $c) => $this->connection->quoteIdentifier($c),
            $columns
        ));
        $tableQuoted = $this->connection->quoteIdentifier($table);
        $whereSql    = '' !== $where ? ' WHERE '.$where : '';

        $sql = "SELECT {$columnList} FROM {$tableQuoted}{$whereSql} ORDER BY {$orderClause} LIMIT :_limit OFFSET :_offset";
        $countSql = "SELECT COUNT(*) FROM {$tableQuoted}{$whereSql}";

        $params['_limit']  = $limit;
        $params['_offset'] = $offset;
        $types['_limit']   = ParameterType::INTEGER;
        $types['_offset']  = ParameterType::INTEGER;

        $rows  = $this->connection->fetchAllAssociative($sql, $params, $types);
        unset($params['_limit'], $params['_offset']);
        $total = (int) $this->connection->fetchOne($countSql, $params, $types);

        $this->outputRecord([
            'table'   => $table,
            'count'   => count($rows),
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'order'   => $orderClause,
            'columns' => $columns,
            'results' => $rows,
        ]);
        return Command::SUCCESS;
    }

    private function isValidTableName(string $table): bool
    {
        return 1 === preg_match('/^tl_[a-z0-9_]{1,60}$/i', $table);
    }

    /**
     * Validates and quotes an `ORDER BY` clause. Accepts a single column or
     * comma-separated list, each optionally followed by `ASC`/`DESC`.
     *
     * @param list<string> $allowedColumns
     */
    private function buildOrderClause(string $raw, array $allowedColumns): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return $this->connection->quoteIdentifier('id').' DESC';
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)));
        if (count($parts) > 3) {
            throw new \InvalidArgumentException('order: maximum 3 columns allowed');
        }

        $out = [];
        $usesId = false;
        foreach ($parts as $part) {
            if (1 !== preg_match('/^([a-zA-Z_][a-zA-Z0-9_]{0,63})(?:\s+(ASC|DESC))?$/i', $part, $m)) {
                throw new \InvalidArgumentException("order: invalid clause: $part");
            }
            $col = $m[1];
            $dir = isset($m[2]) ? strtoupper($m[2]) : 'ASC';
            if (!in_array($col, $allowedColumns, true)) {
                throw new \InvalidArgumentException("order: unknown column: $col");
            }
            if ('id' === $col) {
                $usesId = true;
            }
            $out[] = $this->connection->quoteIdentifier($col).' '.$dir;
        }
        // Stable tie-breaker: append id DESC unless the caller already orders
        // by id. Prevents the "all rows share the same date" trap that broke
        // the agent's "neueste" interpretation on 2026-05-01 — Contao stores
        // tl_news.date at midnight, so two same-day records tie and MySQL's
        // implicit ordering is undefined.
        if (!$usesId && in_array('id', $allowedColumns, true)) {
            $out[] = $this->connection->quoteIdentifier('id').' DESC';
        }
        return implode(', ', $out);
    }

    /**
     * @param list<string> $rawFilters list of "field=value" strings
     * @param list<string> $allowedColumns
     * @return array{0:string,1:array<string,scalar|null>,2:array<string,int>}
     */
    private function buildWhere(array $rawFilters, array $allowedColumns): array
    {
        if ([] === $rawFilters) {
            return ['', [], []];
        }
        if (count($rawFilters) > 10) {
            throw new \InvalidArgumentException('filter: maximum 10 filters allowed');
        }

        $clauses = [];
        $params  = [];
        $types   = [];
        $i       = 0;
        foreach ($rawFilters as $raw) {
            $pos = strpos($raw, '=');
            if (false === $pos || 0 === $pos) {
                throw new \InvalidArgumentException("filter: expected field=value, got: $raw");
            }
            $field = substr($raw, 0, $pos);
            $value = substr($raw, $pos + 1);
            if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $field)) {
                throw new \InvalidArgumentException("filter: invalid field name: $field");
            }
            if (!in_array($field, $allowedColumns, true)) {
                throw new \InvalidArgumentException("filter: unknown column: $field");
            }
            $placeholder = 'f'.$i;
            $clauses[] = $this->connection->quoteIdentifier($field).' = :'.$placeholder;
            // Cast common numeric IDs to integer so MySQL doesn't ignore the index.
            if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                $params[$placeholder] = (int) $value;
                $types[$placeholder]  = ParameterType::INTEGER;
            } else {
                $params[$placeholder] = $value;
                $types[$placeholder]  = ParameterType::STRING;
            }
            $i++;
        }
        return [implode(' AND ', $clauses), $params, $types];
    }

    /**
     * @param list<string> $allowedColumns
     * @return list<string>
     */
    private function resolveColumns(string $fieldsArg, array $allowedColumns, string $table): array
    {
        $fieldsArg = trim($fieldsArg);
        if ('' === $fieldsArg) {
            return $this->defaultColumns($table, $allowedColumns);
        }

        $requested = array_filter(array_map('trim', explode(',', $fieldsArg)));
        $out = [];
        foreach ($requested as $col) {
            if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $col)) {
                throw new \InvalidArgumentException("fields: invalid column: $col");
            }
            if (!in_array($col, $allowedColumns, true)) {
                throw new \InvalidArgumentException("fields: unknown column: $col");
            }
            $out[] = $col;
        }
        if ([] === $out) {
            return $this->defaultColumns($table, $allowedColumns);
        }
        return array_values(array_unique($out));
    }

    /**
     * Curated, identity-focused default per table. Anything more detailed should be
     * fetched via the dedicated *_read tool. The intent is "give me a quick overview
     * I can scan" — not "dump every column".
     *
     * @param list<string> $allowedColumns
     * @return list<string>
     */
    private function defaultColumns(string $table, array $allowedColumns): array
    {
        $defaults = match ($table) {
            'tl_news'           => ['id', 'pid', 'headline', 'date', 'published', 'tstamp'],
            'tl_news_archive'   => ['id', 'title', 'tstamp'],
            'tl_page'           => ['id', 'pid', 'title', 'alias', 'type', 'published', 'tstamp'],
            'tl_article'        => ['id', 'pid', 'title', 'alias', 'inColumn', 'published', 'tstamp'],
            'tl_content'        => ['id', 'pid', 'ptable', 'type', 'headline', 'invisible', 'tstamp'],
            'tl_calendar'       => ['id', 'title', 'tstamp'],
            'tl_calendar_events'=> ['id', 'pid', 'title', 'startTime', 'endTime', 'published', 'tstamp'],
            'tl_files'          => ['id', 'pid', 'name', 'type', 'extension', 'tstamp'],
            default             => ['id', 'tstamp'],
        };
        // Drop any default columns the table doesn't actually have (older Contao versions).
        return array_values(array_filter($defaults, fn ($c) => in_array($c, $allowedColumns, true)));
    }
}
