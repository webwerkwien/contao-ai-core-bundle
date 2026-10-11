<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\StringUtil;

/**
 * Contao's virtual fields, as DC_Table handles them (v1.6.0).
 *
 * Since Contao 5.7 a DCA field may have no column of its own: `targetColumn` names a
 * JSON column (`virtualTarget`) that holds it with others. Contao maps every field
 * without `sql` and without a save path of its own there automatically
 * (VirtualFieldsMappingListener, default column `jsonData`); 6.0 moved the player
 * fields of tl_content in, Consho keeps a payment method's bank details that way.
 *
 * Until v1.6.0 the bundle refused such a field as "not a column". Writing it into the
 * target as it stands would drop every other value of that column, so this does what
 * DC_Table::submit() does: the stored row expanded, the new values over it, folded
 * back into their target. And it reads them the way DC_Table::getCurrentRecord() hands
 * them to callbacks: the target column replaced by its fields.
 *
 * Mirrors `contao.data_container.virtual_fields_handler` (expandFields(),
 * combineFields()) instead of calling it: the handler reads the map through a
 * DcaExtractor, which needs a database, and it exists in 5.7 and 6.0 only. 5.3 has no
 * virtual fields, and here none are seen.
 */
final class VirtualFields
{
    private const HANDLER = 'Contao\\CoreBundle\\DataContainer\\VirtualFieldsHandler';

    /** @internal for tests, which run against Contao 5.3 */
    public static ?bool $supported = null;

    public static function supported(): bool
    {
        return self::$supported ?? class_exists(self::HANDLER);
    }

    /**
     * The virtual fields of a loaded DCA and their target columns, read as
     * DcaExtractor reads them: no `sql`, a `targetColumn` that is a `virtualTarget`
     * with `sql`.
     *
     * @return array<string, string>
     */
    public static function map(string $table): array
    {
        if (!self::supported()) {
            return [];
        }

        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        if (!\is_array($fields)) {
            return [];
        }

        $map = [];

        foreach ($fields as $name => $config) {
            $target = \is_array($config) ? ($config['targetColumn'] ?? null) : null;

            if (!\is_string($target) || '' === $target || null !== ($config['sql'] ?? null)) {
                continue;
            }

            if (($fields[$target]['virtualTarget'] ?? false) && null !== ($fields[$target]['sql'] ?? null)) {
                $map[(string) $name] = $target;
            }
        }

        return $map;
    }

    /**
     * The row with each target column replaced by the virtual fields it holds — what
     * DC_Table::getCurrentRecord() returns in 5.7 and 6.0.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function expand(string $table, array $row): array
    {
        $map = self::map($table);

        if ([] === $map) {
            return $row;
        }

        $expanded = [];

        foreach (array_unique(array_values($map)) as $target) {
            $stored = $row[$target] ?? null;

            if (\is_string($stored) && '' !== $stored) {
                try {
                    $stored = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $stored = null; // as Contao: invalid JSON is ignored
                }
            }

            if (\is_array($stored)) {
                $expanded = [...$expanded, ...$stored];
            }

            unset($row[$target]);
        }

        return [...$row, ...array_intersect_key($expanded, $map)];
    }

    /**
     * The values with their virtual fields folded into the target columns, as JSON;
     * the other values of a target column come from the stored row and are kept. A
     * value list without virtual fields comes back unchanged.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $stored the row as stored, target columns included
     *
     * @return array<string, mixed>
     */
    public static function combine(string $table, array $values, array $stored): array
    {
        $map = self::map($table);
        $new = array_intersect_key($values, $map);

        if ([] === $new) {
            return $values;
        }

        $current = array_intersect_key(self::expand($table, $stored), $map);
        $targets = [];

        foreach ([...$current, ...$new] as $field => $value) {
            $targets[$map[$field]][$field] = method_exists(StringUtil::class, 'ensureStringUuids') ? StringUtil::ensureStringUuids($value) : $value;
        }

        $touched = array_unique(array_values(array_intersect_key($map, $new)));
        $values  = array_diff_key($values, $map);

        foreach ($touched as $target) {
            try {
                // As DBAL's JsonType encodes it, which DC_Table writes the target with.
                $values[$target] = json_encode($targets[$target], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException(\sprintf('The virtual fields of %s.%s cannot be stored as JSON (%s). Nothing was written.', $table, $target, $e->getMessage()), 0, $e);
            }
        }

        return $values;
    }
}
