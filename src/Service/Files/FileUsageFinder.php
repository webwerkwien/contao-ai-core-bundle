<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Files;

use Contao\Controller;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;

/**
 * Where files are still used — the check Contao does not make before deleting one.
 *
 * `DC_Folder::delete()` (5.7.13, 6.0.0) removes a file whatever points at it; an image
 * element keeps the UUID and renders nothing, and there is no `tl_undo` for files. Used by
 * `contao:file:delete` to refuse such a file unless `--force` is given (Nr. 46 of the
 * ConpAI 1.0 acceptance test, 2026-09-16).
 *
 * Searched, in every `tl_` table with a DCA except the history tables:
 *
 * - `fileTree` fields for the binary UUID — single values by equality, lists (serialized)
 *   by containment
 * - text fields (`text`, `textarea`, `inputUnit`) for the UUID as text and for the path —
 *   insert tags like `{{file::…}}`/`{{picture::…}}` and links written into content
 *
 * The database only preselects (`LOCATE`, case-insensitive in text columns); which
 * resource a row really uses is decided here — case-sensitively, and for a path only where
 * it ends: `files/media` is not used by `files/media2/a.jpg` (review 2026-09-16).
 *
 * One query per table and field for all resources, in chunks — not one per resource. A
 * folder of 200 files cost tens of thousands of full scans before v0.19.0.
 *
 * Not searched: templates and style sheets on disk, and values an extension stores
 * outside its DCA. A table whose DCA fails to load is named in `skippedTables`. A clean
 * result is "nothing found in the database", not a guarantee.
 */
final class FileUsageFinder
{
    /** History, not use: a version or undo entry may hold the UUID of anything ever set. */
    private const SKIPPED_TABLES = ['tl_files', 'tl_version', 'tl_undo', 'tl_log'];

    private const TEXT_TYPES = ['text', 'textarea', 'inputUnit'];

    /** Enough to decide and to act on; a file used a hundred times needs no complete list. */
    public const MAX_USAGES = 50;

    /** Resources per query — keeps the statement well below any placeholder limit. */
    private const CHUNK = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function isSearchedTable(string $table): bool
    {
        return str_starts_with($table, 'tl_') && !\in_array($table, self::SKIPPED_TABLES, true);
    }

    /**
     * @param array<string, array<string, mixed>> $fields  DCA fields of one table
     * @param list<string>                         $columns the table's real columns
     *
     * @return array{uuid: list<string>, text: list<string>}
     */
    public static function searchableColumns(array $fields, array $columns): array
    {
        $result = ['uuid' => [], 'text' => []];

        foreach ($fields as $name => $def) {
            if (!\in_array($name, $columns, true)) {
                continue;
            }

            $type = $def['inputType'] ?? null;

            if ('fileTree' === $type) {
                $result['uuid'][] = $name;
            } elseif (\in_array($type, self::TEXT_TYPES, true)) {
                $result['text'][] = $name;
            }
        }

        return $result;
    }

    /**
     * Whether a text names the path — as a whole path, not as the start of a longer name.
     *
     * Before it: nothing, or a character that cannot belong to a path segment (`/` counts
     * as a boundary, for `/files/…` and full URLs). After it: nothing, `/` (something
     * inside a folder), a character that cannot continue a file name, or a dot that ends a
     * sentence (`files/a.png.` names the file, `files/a.png.bak` does not).
     *
     * Text that is not valid UTF-8 is matched byte-wise instead of being taken for "no use"
     * (`preg_match` with `u` returns false on it — pre-release review, 2026-09-16).
     */
    public static function textNamesPath(string $text, string $path): bool
    {
        $pattern = '~(?<![\w.\-])' . preg_quote($path, '~') . '(?!\.?[\w\-])~';
        $result  = preg_match($pattern . 'u', $text);

        return 1 === (false === $result ? preg_match($pattern, $text) : $result);
    }

    /**
     * @param list<array{path: string, uuid: string|null}> $resources binary UUIDs, as in tl_files
     *
     * @return array{usages: list<array{table: string, id: int, field: string, path: string}>, skippedTables: list<string>}
     */
    public function find(array $resources): array
    {
        $usages  = [];
        $skipped = [];
        $schema  = $this->connection->createSchemaManager();

        foreach ($schema->listTableNames() as $table) {
            if (!self::isSearchedTable($table)) {
                continue;
            }

            $fields = $this->dcaFields($table);

            if (null === $fields) {
                $skipped[] = $table;
                continue;
            }
            if ([] === $fields) {
                continue;
            }

            $columns = array_map(
                static fn ($column): string => $column->getName(),
                array_values($schema->listTableColumns($table)),
            );
            $search = self::searchableColumns($fields, $columns);

            foreach (array_chunk($resources, self::CHUNK) as $chunk) {
                foreach ($search['uuid'] as $field) {
                    $this->collect($usages, $table, $field, $chunk, true);

                    if (\count($usages) >= self::MAX_USAGES) {
                        break 3;
                    }
                }

                foreach ($search['text'] as $field) {
                    $this->collect($usages, $table, $field, $chunk, false);

                    if (\count($usages) >= self::MAX_USAGES) {
                        break 3;
                    }
                }
            }
        }

        return ['usages' => \array_slice(array_values($usages), 0, self::MAX_USAGES), 'skippedTables' => $skipped];
    }

    /**
     * @param array<string, array{table: string, id: int, field: string, path: string}> $usages
     * @param list<array{path: string, uuid: string|null}>                                $chunk
     */
    private function collect(array &$usages, string $table, string $field, array $chunk, bool $binary): void
    {
        $column     = 'q.' . $this->connection->quoteIdentifier($field);
        $conditions = [];
        $params     = [];

        foreach ($chunk as $resource) {
            if ($binary) {
                if (null === $resource['uuid']) {
                    continue;
                }
                // Equality for a single value, containment for a serialized list.
                $conditions[] = "{$column} = ? OR LOCATE(?, {$column}) > 0";
                array_push($params, $resource['uuid'], $resource['uuid']);
            } else {
                if (null !== $resource['uuid']) {
                    $conditions[] = "LOCATE(?, {$column}) > 0";
                    $params[]     = StringUtil::binToUuid($resource['uuid']);
                }
                $conditions[] = "LOCATE(?, {$column}) > 0";
                $params[]     = $resource['path'];
            }
        }

        if ([] === $conditions) {
            return;
        }

        // Streamed rather than limited: rows the preselection lets through but the boundary
        // check rejects must not crowd out a real use.
        $rows = $this->connection->executeQuery(
            "SELECT q.id, {$column} FROM " . $this->connection->quoteIdentifier($table) . ' q WHERE ' . implode(' OR ', $conditions),
            $params,
        )->iterateNumeric();

        foreach ($rows as [$id, $value]) {
            $value = (string) $value;

            foreach ($chunk as $resource) {
                $uses = $binary
                    ? null !== $resource['uuid'] && str_contains($value, $resource['uuid'])
                    : (null !== $resource['uuid'] && false !== stripos($value, StringUtil::binToUuid($resource['uuid'])))
                        || self::textNamesPath($value, $resource['path']);

                if (!$uses) {
                    continue;
                }

                $usages[$table . '.' . $id . '.' . $field . '.' . $resource['path']] = [
                    'table' => $table,
                    'id'    => (int) $id,
                    'field' => $field,
                    'path'  => $resource['path'],
                ];

                if (\count($usages) >= self::MAX_USAGES) {
                    return;
                }
            }
        }
    }

    /**
     * The DCA fields, or null when the DCA could not be loaded.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function dcaFields(string $table): ?array
    {
        try {
            if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
                Controller::loadDataContainer($table);
            }
        } catch (\Throwable) {
            return null; // reported in skippedTables — a clean result must say what it covers
        }

        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        return \is_array($fields) ? $fields : [];
    }
}
