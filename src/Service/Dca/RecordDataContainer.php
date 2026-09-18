<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\DataContainer;
use Contao\DC_Table;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A DC_Table for calling Contao's own callbacks from the console, answering with a
 * given record instead of asking the permission voters.
 *
 * `getCurrentRecord()` checks read permission and fails without a back-end user;
 * `getActiveRecord()` and the palette code go through it. Both are overridden, and the
 * constructor — which needs a request — is replaced. The signatures are identical in
 * Contao 5.3, 5.7 and 6.0. Used by OptionsResolver (options callbacks),
 * DcaPaletteCommand (DataContainer::getPalette()) and ContaoAlias.
 *
 * The record looks like the one the back end hands its callbacks (v0.25.0):
 *
 * - **Every column is there.** In the back end even a new element is a full row, with
 *   each column's default, before its palette is built. The given values are laid over
 *   the column defaults, so a callback may read any column — Contao's AccordionListener
 *   reads `ptable` and warned "Undefined array key" on every palette of tl_content.
 * - **Another row is another row.** `getCurrentRecord($id, $table)` for a different ID
 *   or table reads that row, as Contao's preloadCurrentRecords() does (`SELECT *`), and
 *   answers null when there is none. It used to answer the own record for any request,
 *   so the AccordionListener got the element back as its own parent.
 *
 * An explicit ID 0 counts as no ID, as in Contao's own `$id ?: $this->intId` — a
 * record without `pid` asking for its parent gets itself, there as here.
 *
 * Without a database (unit tests, no container) the record stays as given and other
 * rows answer null. No permission check on the other row: Contao's voters need a
 * back-end user, which the console has not. The backend bundle runs these commands in a
 * logged-in user's request, and there the read skips the voters too — acceptable
 * because the row only reaches the callback that asked for it (a parent's `type`, an
 * option list), never the caller's answer.
 *
 * A factory rather than a named subclass: a class under src/ extending DC_Table would
 * be picked up as a service and fail to autowire.
 */
final class RecordDataContainer
{
    /** @var \WeakMap<Connection, array<string, array<string, mixed>>>|null column defaults per table */
    private static ?\WeakMap $defaults = null;

    /**
     * @param array<string, mixed> $record
     */
    public static function create(string $table, int $id, array $record, ?Connection $db = null): DataContainer
    {
        $db ??= self::connection();

        if ([] !== $record && null !== $db) {
            $record = array_replace(self::columnDefaults($db, $table), $record);
        }

        return new class($table, $id, $record, $db) extends DC_Table {
            /**
             * @param array<string, mixed> $record
             */
            public function __construct(string $table, int $id, private readonly array $record, private readonly ?Connection $db)
            {
                $this->strTable = $table;
                $this->intId    = $id;
                // The deprecated `$dc->activeRecord` reads this property in 5.3, 5.7
                // and 6.0 alike — and it is what Contao's alias callbacks still use.
                $this->objActiveRecord = [] === $record ? null : (object) $record;
            }

            public function getCurrentRecord(int|string|null $id = null, string|null $table = null): array|null
            {
                // Contao's own defaulting: `$id ?: $this->intId`, `$table ?: $this->strTable`.
                $id    = (int) ($id ?: $this->intId);
                $table = $table ?: $this->strTable;

                if ($id === $this->intId && $table === $this->strTable) {
                    return [] === $this->record ? null : $this->record;
                }

                if (null === $this->db || $id <= 0) {
                    return null;
                }

                $row = $this->db->fetchAssociative('SELECT * FROM ' . $this->db->quoteIdentifier($table) . ' WHERE id = ?', [$id]);

                return false === $row ? null : $row;
            }

            public function getActiveRecord(): array|null
            {
                return [] === $this->record ? null : $this->record;
            }
        };
    }

    /**
     * Each column with the default a new row gets, typed as `SELECT *` returns it
     * through pdo_mysql on PHP 8.1+: integers as int, everything else as string.
     *
     * @return array<string, mixed>
     */
    private static function columnDefaults(Connection $db, string $table): array
    {
        self::$defaults ??= new \WeakMap();

        if (isset(self::$defaults[$db][$table])) {
            return self::$defaults[$db][$table];
        }

        $defaults = [];
        foreach ($db->createSchemaManager()->listTableColumns($table) as $column) {
            $defaults[$column->getName()] = self::rowValue($column);
        }

        $known          = self::$defaults[$db] ?? [];
        $known[$table]  = $defaults;
        self::$defaults[$db] = $known;

        return $defaults;
    }

    private static function rowValue(Column $column): mixed
    {
        $default = $column->getDefault();

        if (null === $default) {
            // A NOT NULL column without a default reads back as an empty value, not null.
            return $column->getNotnull() ? (self::isInteger($column) ? 0 : '') : null;
        }

        if (!\is_scalar($default)) {
            // DBAL 4 (Contao 6) hands expressions such as CURRENT_TIMESTAMP as objects;
            // a row would hold the evaluated value, which cannot be known here.
            return null;
        }

        return self::isInteger($column) && is_numeric($default) ? (int) $default : (string) $default;
    }

    private static function isInteger(Column $column): bool
    {
        return \in_array($column->getType()::class, [IntegerType::class, SmallIntType::class, BigIntType::class, BooleanType::class], true);
    }

    private static function connection(): ?Connection
    {
        // Declared non-null, but null until a kernel has booted — as in the unit tests
        // (see NeedsContaoContainerTrait).
        /** @var ContainerInterface|null $container */
        $container = System::getContainer();

        if (null === $container || !$container->has('database_connection')) {
            return null;
        }

        $db = $container->get('database_connection');

        return $db instanceof Connection ? $db : null;
    }
}
