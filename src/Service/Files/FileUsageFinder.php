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
 * Not searched: templates and style sheets on disk, and values an extension stores
 * outside its DCA. A clean result is "nothing found in the database", not a guarantee.
 */
final class FileUsageFinder
{
    /** History, not use: a version or undo entry may hold the UUID of anything ever set. */
    private const SKIPPED_TABLES = ['tl_files', 'tl_version', 'tl_undo', 'tl_log'];

    private const TEXT_TYPES = ['text', 'textarea', 'inputUnit'];

    /** Enough to decide and to act on; a file used a hundred times needs no complete list. */
    private const MAX_USAGES = 50;

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
     * @param list<array{path: string, uuid: string|null}> $resources binary UUIDs, as in tl_files
     *
     * @return list<array{table: string, id: int, field: string, path: string}>
     */
    public function find(array $resources): array
    {
        $usages = [];

        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            if (!self::isSearchedTable($table)) {
                continue;
            }

            $fields = $this->dcaFields($table);

            if ([] === $fields) {
                continue;
            }

            $columns = array_map(
                static fn ($column): string => $column->getName(),
                array_values($this->connection->createSchemaManager()->listTableColumns($table)),
            );
            $search = self::searchableColumns($fields, $columns);

            foreach ($resources as $resource) {
                $needles = [];

                if (null !== $resource['uuid']) {
                    foreach ($search['uuid'] as $field) {
                        // Equality for a single value, containment for a serialized list.
                        $needles[] = [$field, 'q.' . $this->connection->quoteIdentifier($field) . ' = ? OR LOCATE(?, q.' . $this->connection->quoteIdentifier($field) . ') > 0', [$resource['uuid'], $resource['uuid']]];
                    }

                    foreach ($search['text'] as $field) {
                        $needles[] = [$field, 'LOCATE(?, q.' . $this->connection->quoteIdentifier($field) . ') > 0', [StringUtil::binToUuid($resource['uuid'])]];
                    }
                }

                foreach ($search['text'] as $field) {
                    $needles[] = [$field, 'LOCATE(?, q.' . $this->connection->quoteIdentifier($field) . ') > 0', [$resource['path']]];
                }

                foreach ($needles as [$field, $condition, $params]) {
                    $ids = $this->connection->fetchFirstColumn(
                        'SELECT q.id FROM ' . $this->connection->quoteIdentifier($table) . ' q WHERE ' . $condition . ' LIMIT ' . self::MAX_USAGES,
                        $params,
                    );

                    foreach ($ids as $id) {
                        $usages[$table . '.' . $id . '.' . $field . '.' . $resource['path']] = [
                            'table' => $table,
                            'id'    => (int) $id,
                            'field' => $field,
                            'path'  => $resource['path'],
                        ];

                        if (\count($usages) >= self::MAX_USAGES) {
                            return array_values($usages);
                        }
                    }
                }
            }
        }

        return array_values($usages);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function dcaFields(string $table): array
    {
        try {
            if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
                Controller::loadDataContainer($table);
            }
        } catch (\Throwable) {
            return []; // a table without a loadable DCA is not searched
        }

        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        return \is_array($fields) ? $fields : [];
    }
}
