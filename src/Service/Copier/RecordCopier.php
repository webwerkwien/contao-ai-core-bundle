<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Copier;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\CopiesSourceRows;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\DcaCopyReset;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Copies one record and its children the way `DC_Table::copy()` and `copyChildren()`
 * do (5.3 to 6.0):
 *
 * - every field, whatever the caller may edit; the field rights apply when the copy
 *   is changed afterwards, as in the back end's edit form that follows a copy
 * - what Contao resets is read from the DCA (DcaCopyReset)
 * - `config.markAsCopy` on the copied record only (MarkAsCopy)
 * - children per `ctable`, matched on pid and, for a `dynamicPtable` table, on ptable,
 *   in `sorting, id` order; tables with `doNotCopyRecords` stay behind
 * - `oncopy` for each child after its own children, for the copied record last
 *
 * Unlike the back end, each copied row gets a first version, as the cloners do.
 *
 * The whole source tree is read before the first row is written, so a copy placed
 * below its own source never finds itself as a child.
 *
 * Rows go through DBAL, never the model layer (see CopiesSourceRows). No transaction
 * here: the calling command runs in one.
 */
final class RecordCopier
{
    use CopiesSourceRows;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
        private readonly ?DcaCallbacks $dcaCallbacks = null,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides values the copy gets instead of the source's: its position and any value the table's own rules derive
     *
     * @return array{id: int, children: int, notCopied: list<string>}
     *
     * @throws \InvalidArgumentException when the source does not exist
     */
    public function copy(string $table, int $sourceId, array $overrides, string $operator): array
    {
        $this->framework->initialize();

        $fields = $this->fields($table);
        $source = $this->fetchSourceRow($table, $sourceId);
        $tree   = $this->readChildren($table, $sourceId);

        $mark = $GLOBALS['TL_DCA'][$table]['config']['markAsCopy'] ?? null;
        if (\is_string($mark) && \array_key_exists($mark, $source) && !\array_key_exists($mark, $overrides)) {
            $overrides[$mark] = MarkAsCopy::apply($this->copyLabel(), $source[$mark], 'inputUnit' === ($fields[$mark]['inputType'] ?? null));
        }

        ['id' => $newId, 'row' => $copy] = $this->insert($table, $source, $overrides);
        $this->versionManager->createInitialVersion($table, $newId, $operator);

        $children = $this->writeChildren($tree, $newId, $operator);

        $this->dcaCallbacks?->copy($table, $newId, $sourceId);

        return ['id' => $newId, 'children' => $children, 'notCopied' => $this->notCopied($fields, $source, $copy)];
    }

    /**
     * @return list<array{table: string, row: array<string, mixed>, children: list<mixed>}>
     */
    private function readChildren(string $table, int $id): array
    {
        $nodes = [];

        foreach ($this->childTables($table) as $childTable) {
            $dynamic = (bool) ($GLOBALS['TL_DCA'][$childTable]['config']['dynamicPtable'] ?? false);
            $sql     = 'SELECT * FROM ' . $this->connection->quoteIdentifier($childTable) . ' WHERE pid = ?'
                . ($dynamic ? ' AND ptable = ?' : '')
                . (isset($this->fields($childTable)['sorting']) ? ' ORDER BY sorting, id' : ' ORDER BY id');

            foreach ($this->connection->fetchAllAssociative($sql, $dynamic ? [$id, $table] : [$id]) as $row) {
                $nodes[] = ['table' => $childTable, 'row' => $row, 'children' => $this->readChildren($childTable, (int) $row['id'])];
            }
        }

        return $nodes;
    }

    /**
     * @param list<array{table: string, row: array<string, mixed>, children: list<mixed>}> $nodes
     */
    private function writeChildren(array $nodes, int $newPid, string $operator): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            ['id' => $newId] = $this->insert($node['table'], $node['row'], ['pid' => $newPid]);
            $this->versionManager->createInitialVersion($node['table'], $newId, $operator);
            ++$count;

            /** @var list<array{table: string, row: array<string, mixed>, children: list<mixed>}> $grandchildren */
            $grandchildren = $node['children'];
            $count += $this->writeChildren($grandchildren, $newId, $operator);

            $this->dcaCallbacks?->copy($node['table'], $newId, (int) $node['row']['id']);
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function childTables(string $table): array
    {
        $tables = [];

        foreach ((array) ($GLOBALS['TL_DCA'][$table]['config']['ctable'] ?? []) as $child) {
            $child = (string) $child;
            $this->fields($child);

            if (!($GLOBALS['TL_DCA'][$child]['config']['doNotCopyRecords'] ?? false)) {
                $tables[] = $child;
            }
        }

        return $tables;
    }

    /**
     * Write a copy of $row: reset as Contao does, then $overrides. As
     * AbstractDcaCloner::insertCopy(): column names quoted (tl_layout has `rows`),
     * booleans as integers, since strict SQL refuses '' in a tinyint.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $overrides
     *
     * @return array{id: int, row: array<string, mixed>}
     */
    private function insert(string $table, array $row, array $overrides): array
    {
        unset($row['id']);

        $row           = array_replace($row, DcaCopyReset::values($row, $this->fields($table)));
        $row['tstamp'] = time();
        $row           = array_replace($row, $overrides);

        $data = [];
        foreach ($row as $column => $value) {
            $data[$this->connection->quoteIdentifier((string) $column)] = \is_bool($value) ? (int) $value : $value;
        }

        $this->connection->insert($this->connection->quoteIdentifier($table), $data);

        return ['id' => (int) $this->connection->lastInsertId(), 'row' => $row];
    }

    /**
     * The fields Contao's reset emptied or set back where the source had a value.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $source
     * @param array<string, mixed> $copy
     *
     * @return list<string>
     */
    private function notCopied(array $fields, array $source, array $copy): array
    {
        $names = [];

        foreach (array_keys(DcaCopyReset::values($source, $fields)) as $field) {
            $value = $source[$field] ?? null;

            if (null === $value || '' === $value || '0' === $value || 0 === $value || false === $value) {
                continue;
            }

            $copied = $copy[$field] ?? null;
            if ((string) $value !== (string) (\is_bool($copied) ? (int) $copied : $copied)) {
                $names[] = (string) $field;
            }
        }

        return $names;
    }

    /**
     * `MSC.copyOf` of the loaded language: in the back end the editor's, on the
     * console English, as Contao initializes without a request.
     */
    private function copyLabel(): string
    {
        if (!isset($GLOBALS['TL_LANG']['MSC']['copyOf'])) {
            $this->framework->getAdapter(System::class)->loadLanguageFile('default');
        }

        $label = $GLOBALS['TL_LANG']['MSC']['copyOf'] ?? null;

        return \is_string($label) && str_contains($label, '%s') ? $label : '%s (copy)';
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(string $table): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            $this->framework->getAdapter(Controller::class)->loadDataContainer($table);
        }

        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        return \is_array($fields) ? $fields : [];
    }
}
