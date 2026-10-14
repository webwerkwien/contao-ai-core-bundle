<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * A cloner for a record and its direct children, copied the way Contao copies them.
 *
 * Added in v1.8.0 for forms, newsletter channels, modules and layouts. Unlike the
 * older cloners it never goes through the model layer: rows are read and written
 * over DBAL as `DC_Table::copy()` does (see CopiesSourceRows for why the model layer
 * corrupts `tinyint` values), and what Contao does not copy is read from the DCA
 * (DcaCopyReset) instead of being listed per table.
 *
 * What stays as in the source: every reference. A cloned module still points at the
 * same pages, a cloned layout at the same modules. The answer names those fields in
 * `references`, so a caller cloning for another language knows what to adjust with
 * the update commands.
 */
abstract class AbstractDcaCloner implements EntityClonerInterface
{
    use CopiesSourceRows;
    use FiltersModifications;
    use GeneratesUniqueAlias;

    /** Input types whose value points at another record or a file. */
    private const REFERENCE_INPUT_TYPES = ['pageTree', 'fileTree', 'picker', 'moduleWizard'];

    public function __construct(
        protected readonly Connection $connection,
        protected readonly ContaoFramework $framework,
        protected readonly VersionManager $versionManager,
        protected readonly ?DcaCallbacks $dcaCallbacks = null,
    ) {
    }

    /** The table of the cloned record. */
    abstract protected function table(): string;

    /** The field that names the record; the only one `--modifications` may set. */
    abstract protected function nameField(): string;

    /**
     * Child tables copied along, in this order. The first one is what `count` counts.
     *
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    protected function childTables(array $options): array
    {
        return [];
    }

    /**
     * Values derived anew for a copied row, after the reset, e.g. a unique alias.
     *
     * @param array<string, mixed> $row the row about to be written
     *
     * @return array<string, mixed>
     */
    protected function derivedValues(string $table, array $row): array
    {
        return [];
    }

    /** Whether copied rows of $table get a first version. */
    protected function versionsFor(string $table): bool
    {
        return true;
    }

    /**
     * Additional keys for the answer.
     *
     * @param array<string, mixed> $options
     *
     * @return array{recipients_copied?: bool}
     */
    protected function extraResult(array $options): array
    {
        return [];
    }

    public function supports(string $table): bool
    {
        return $this->table() === $table;
    }

    /**
     * @return array{id: int, table: string, count: int, children: array<string, int>, not_copied: list<string>, references: list<string>, ignored_modifications: list<string>, recipients_copied?: bool}
     */
    public function clone(int $sourceId, array $modifications, string $operator, array $options = []): array
    {
        $this->framework->initialize();

        $table     = $this->table();
        $nameField = $this->nameField();
        $source    = $this->fetchSourceRow($table, $sourceId);

        ['accepted' => $accepted, 'ignored' => $ignored] = $this->partitionModifications($modifications, [$nameField]);

        $name = \array_key_exists($nameField, $accepted)
            ? (string) $accepted[$nameField]
            : ((string) ($source[$nameField] ?? '')) . ' (Kopie)';

        return $this->connection->transactional(function () use ($table, $nameField, $name, $source, $sourceId, $ignored, $operator, $options): array {
            ['id' => $newId, 'row' => $clone] = $this->insertCopy($table, $source, [$nameField => $name]);
            $this->firstVersion($table, $newId, $operator);

            $children = [];
            foreach ($this->childTables($options) as $childTable) {
                $children[$childTable] = 0;

                foreach ($this->childRows($childTable, $sourceId) as $row) {
                    ['id' => $newChildId] = $this->insertCopy($childTable, $row, ['pid' => $newId]);
                    $this->firstVersion($childTable, $newChildId, $operator);
                    // As DC_Table::copyChildren(): oncopy per child, the root last.
                    $this->dcaCallbacks?->copy($childTable, $newChildId, (int) $row['id']);
                    ++$children[$childTable];
                }
            }

            $this->dcaCallbacks?->copy($table, $newId, $sourceId);

            return [
                'id'       => $newId,
                'table'    => $table,
                'count'    => (int) (array_values($children)[0] ?? 0),
                'children' => $children,
                // What Contao's copy does not take along either, where the source had a value.
                'not_copied' => $this->notCopied($source, $clone, $nameField),
                'references' => $this->references($table, $clone),
                // Overrides this cloner refused. Empty on a clean call; never omitted.
                'ignored_modifications' => $ignored,
            ] + $this->extraResult($options);
        });
    }

    /**
     * Write a copy of $row: reset as Contao does, then $overrides, then derived values.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $overrides
     *
     * @return array{id: int, row: array<string, mixed>}
     */
    private function insertCopy(string $table, array $row, array $overrides): array
    {
        unset($row['id']);

        $row = array_replace($row, DcaCopyReset::values($row, $this->fields($table)));
        $row['tstamp'] = time();
        $row = array_replace($row, $overrides);
        $row = array_replace($row, $this->derivedValues($table, $row));

        // Quoted: tl_layout has a column `rows`, a reserved word since MySQL 8.0.2.
        // Booleans as integers: DBAL binds untyped values as strings, and `false` would
        // arrive as '' — which a tinyint column refuses in strict SQL mode (the empty
        // value of a boolean field is `false`, see Widget::getEmptyValueByFieldType()).
        $data = [];
        foreach ($row as $column => $value) {
            $data[$this->connection->quoteIdentifier((string) $column)] = \is_bool($value) ? (int) $value : $value;
        }

        $this->connection->insert($this->connection->quoteIdentifier($table), $data);

        return ['id' => (int) $this->connection->lastInsertId(), 'row' => $row];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function childRows(string $table, int $pid): array
    {
        $order = isset($this->fields($table)['sorting']) ? 'sorting, id' : 'id';

        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE pid = ? ORDER BY ' . $order,
            [$pid],
        );
    }

    private function firstVersion(string $table, int $id, string $operator): void
    {
        if ($this->versionsFor($table)) {
            $this->versionManager->createInitialVersion($table, $id, $operator);
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $clone
     *
     * @return list<string>
     */
    private function notCopied(array $source, array $clone, string $nameField): array
    {
        $fields = [];

        foreach (array_keys(DcaCopyReset::values($source, $this->fields($this->table()))) as $field) {
            if ($field !== $nameField && !self::isEmpty($source[$field] ?? null) && (string) $source[$field] !== (string) ($clone[$field] ?? '')) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The fields of the clone that point at other records or files and have a value.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function references(string $table, array $row): array
    {
        $references = [];

        foreach ($this->fields($table) as $field => $definition) {
            if ('pid' === $field || !\is_array($definition) || self::isEmpty($row[$field] ?? null)) {
                continue;
            }

            if (isset($definition['foreignKey']) || isset($definition['relation'])
                || \in_array($definition['inputType'] ?? null, self::REFERENCE_INPUT_TYPES, true)) {
                $references[] = (string) $field;
            }
        }

        return $references;
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

    private static function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value || '0' === $value || 0 === $value || 'a:0:{}' === $value;
    }
}
