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
        // Default deliberately empty rather than 'id DESC': buildOrderClause()
        // already has an empty branch, and only it knows whether the table has
        // an `id` at all. As a literal default the string went through the
        // validator and a table without `id` — tl_search_index — was rejected
        // for a sort order nobody had asked for.
        $this->addOption('order',  null, InputOption::VALUE_REQUIRED, 'ORDER BY clause, e.g. "tstamp DESC". Default: id DESC where the table has an id.', '');
        $this->addOption(
            'filter', null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Field=value equality filter (repeatable), e.g. --filter pid=5 --filter published=1',
            []
        );
        $this->addOption(
            'filter-prefix', null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Field=prefix match (repeatable), e.g. --filter-prefix path=files/media. '
            . 'The prefix is matched literally; % and _ in it are not wildcards.',
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
        // `id` and `tstamp` are not always declared in DCA fields, so they are added
        // here — but only where the table really has them. The comment that used to
        // stand here said they "always exist in tl_* tables"; on a live 5.7 install
        // four do without `tstamp` (tl_opt_in_related, tl_newsletter_deny_list,
        // tl_search_index, tl_search_term) and tl_search_index has no `id` either.
        // Adding them by decree put a non-existent column into the SELECT, and the
        // command died with an uncaught SQLSTATE[42S22] and exit 255 instead of
        // answering. Intersecting with the real schema also covers the other half of
        // the same problem: a DCA field with no column behind it.
        $allowedColumns = $this->existingColumns($table, array_unique(array_merge(['id', 'tstamp'], $dcaFields)));
        if ([] === $allowedColumns) {
            return $this->outputError("No readable columns for table: $table");
        }

        try {
            $orderClause = $this->buildOrderClause((string) $this->input->getOption('order'), $allowedColumns);
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        $rawFilters = (array) $this->input->getOption('filter');
        try {
            [$where, $params, $types] = $this->buildWhere(
                $rawFilters,
                $allowedColumns,
                (array) $this->input->getOption('filter-prefix'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        // resolveColumns() throws the same \InvalidArgumentException as the two
        // calls above, but was the one of the three left unguarded — so an
        // unknown --fields column came back as an uncaught PHP exception with a
        // stack trace on stderr, while an unknown --order or --filter column
        // got the structured message it was supposed to. Found live on
        // 2026-08-31. RecordListTool passes the same failure to the browser
        // chat, where a model naming a column that does not exist learned
        // nothing it could act on.
        try {
            $columns = $this->resolveColumns(
                (string) $this->input->getOption('fields'),
                $allowedColumns,
                $table,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

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

        // A fileTree column is 16 raw bytes in the database. outputRecord()
        // encodes with JSON_INVALID_UTF8_SUBSTITUTE, so without this every one
        // of them leaves as a row of U+FFFD — the value destroyed on the way
        // out, exactly as in the bug fixed for the model read path in v0.2.15.
        // This command reads with plain DBAL and so never passed through that
        // fix; it is also the only read command that accepts an arbitrary
        // table, which makes it the likeliest to meet an unfamiliar one.
        $rows = array_map(fn (array $row): array => $this->convertFileTreeFieldsToUuid($table, $row), $rows);

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
     * Keep only those candidate columns the table actually has.
     *
     * Compared case-insensitively, and the *candidate's* spelling is what
     * survives. That is not fussiness: Doctrine's listTableColumns() lowercases
     * its array keys, so `singleSRC` comes back as `singlesrc`. A plain
     * array_intersect against those keys would therefore drop every camelCase
     * column Contao has — singleSRC, multiSRC, pageTitle, cspReportLog — while
     * appearing to work, because most core columns are lowercase anyway.
     * MySQL resolves column names case-insensitively, so comparing in lower
     * case and emitting the DCA's spelling is both safe and correct.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private function existingColumns(string $table, array $candidates): array
    {
        $actual = array_map(
            'strtolower',
            array_keys($this->connection->createSchemaManager()->listTableColumns($table)),
        );

        return array_values(array_filter(
            $candidates,
            static fn (string $column): bool => \in_array(strtolower($column), $actual, true),
        ));
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
            // Nearly every Contao table has an `id`, but not all: tl_search_index
            // is a pure join table (pid, termId, relevance). Ordering it by a
            // column that is not there produced a hard SQL error, so fall back
            // to the first column the table really has. Deterministic ordering
            // still matters more than a pretty one — see the tie-breaker below.
            $column = \in_array('id', $allowedColumns, true) ? 'id' : ($allowedColumns[0] ?? null);

            if (null === $column) {
                throw new \InvalidArgumentException('order: the table has no readable column to sort by');
            }

            return $this->connection->quoteIdentifier($column).' DESC';
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
    private function buildWhere(array $rawFilters, array $allowedColumns, array $rawPrefixes = []): array
    {
        if ([] === $rawFilters && [] === $rawPrefixes) {
            return ['', [], []];
        }
        if (count($rawFilters) + count($rawPrefixes) > 10) {
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

        // Prefix matching, for the one shape equality cannot express: everything
        // below a folder. `tl_files` is the case that needs it — a file listing
        // scoped to `files/media` — and it was the last listing that could not
        // move off hand-written SQL for want of it.
        //
        // The value is escaped before the % is appended, so a literal percent or
        // underscore stays literal. The caller is naming a prefix, not handing
        // over a LIKE pattern, and the difference matters: `%` would otherwise
        // turn a scoped listing into a full table scan.
        foreach ($rawPrefixes as $raw) {
            $pos = strpos($raw, '=');
            if (false === $pos || 0 === $pos) {
                throw new \InvalidArgumentException("filter-prefix: expected field=prefix, got: $raw");
            }
            $field = substr($raw, 0, $pos);
            $value = substr($raw, $pos + 1);
            if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $field)) {
                throw new \InvalidArgumentException("filter-prefix: invalid field name: $field");
            }
            if (!in_array($field, $allowedColumns, true)) {
                throw new \InvalidArgumentException("filter-prefix: unknown column: $field");
            }
            if ('' === $value) {
                throw new \InvalidArgumentException("filter-prefix: empty prefix for: $field");
            }

            $placeholder = 'p'.$i;
            $clauses[] = $this->connection->quoteIdentifier($field).' LIKE :'.$placeholder;
            $params[$placeholder] = addcslashes($value, '%_\\').'%';
            $types[$placeholder]  = ParameterType::STRING;
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
            'tl_faq_category'   => ['id', 'title', 'tstamp'],
            'tl_faq'            => ['id', 'pid', 'question', 'published', 'tstamp'],
            'tl_files'          => ['id', 'pid', 'name', 'type', 'extension', 'tstamp'],
            default             => $this->labelColumns($table),
        };
        // Drop any default columns the table doesn't actually have (older Contao
        // versions, or a label field that is computed rather than stored).
        $columns = array_values(array_filter($defaults, fn ($c) => in_array($c, $allowedColumns, true)));

        // Nothing survived: the table has neither `id` nor `tstamp` nor a label
        // field — tl_search_index is the one such table on a stock 5.7. An empty
        // list used to become `SELECT  FROM …`, an SQL syntax error and exit 255.
        // Showing every readable column is the honest answer for a table this
        // narrow, and --limit still bounds the result.
        return [] !== $columns ? $columns : $allowedColumns;
    }

    /**
     * What Contao itself shows for a table it has no curated list for.
     *
     * `list.label.fields` is the column set of the back end's own list view —
     * the DCA's answer to "which fields identify this record". Using it here
     * means an unfamiliar table describes itself the way Contao describes it,
     * instead of the caller having to guess column names.
     *
     * Until 2026-08-31 the default arm returned `['id', 'tstamp']` for
     * everything outside the ten curated tables, which made the zero-argument
     * call useless in exactly the case this command exists for: `record:list
     * tl_image_size` answered an "I do not know this table, show me what is in
     * it" question with two columns that say nothing. Finding a usable size
     * meant a round of dca:schema, picking from some thirty fields, and asking
     * again.
     *
     * Measured against every table of a live 5.7 install before choosing this:
     * of the 29 non-curated tables with a DCA, 22 declare `label.fields` and 7
     * declare nothing — and those 7 are `tl_search*`, `tl_version`,
     * `tl_opt_in_related`, `tl_comments_notify`, `tl_newsletter_deny_list`:
     * system tables with no back end list at all, where `id` and `tstamp`
     * genuinely is the whole story. So the fallback lands where it belongs.
     *
     * `list.sorting.fields` was considered as a middle tier and dropped. It
     * fired on none of the 29, and it answers a different question — what to
     * sort by, not what a record is.
     *
     * The ten curated tables keep their hand-picked lists, which are richer
     * than their label fields (`tl_page` labels with `title` alone; the curated
     * list adds pid, alias, type and published). RecordListTool in the backend
     * bundle allows exactly those ten tables and no others, so the browser chat
     * cannot reach this method at all — verified by comparing both lists, not
     * assumed.
     *
     * @return list<string>
     */
    private function labelColumns(string $table): array
    {
        $fields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'] ?? [];

        if (!\is_array($fields) || [] === $fields) {
            return ['id', 'tstamp'];
        }

        // `id` first, `tstamp` last, label fields in the order Contao lists
        // them. array_unique guards a label field that is literally `id`.
        return array_values(array_unique(['id', ...array_filter($fields, 'is_string'), 'tstamp']));
    }
}
