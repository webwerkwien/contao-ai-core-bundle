<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;

/**
 * The columns a table really has, by name (v1.16.1).
 *
 * Not the keys of Doctrine's listTableColumns(). Those are
 * `strtolower(getQuotedName())`, and getQuotedName() wraps every name on the
 * platform's keyword list in backticks: `rows` in tl_layout comes back as
 * "`rows`". Compared against those keys, such a column did not exist —
 * record:list refused to read or filter it, and undo:restore put a deleted
 * layout back without its `rows` value, silently. getName() is the name as the
 * database has it, in its own case.
 *
 * DBAL 4.4 deprecates both getName() and listTableColumns() in favour of
 * getObjectName() and introspectTableColumns(); this is the one place to switch.
 */
final class TableColumns
{
    /**
     * @return list<string> the column names as the database spells them
     */
    public static function names(Connection $connection, string $table): array
    {
        return array_values(array_map(
            static fn (Column $column): string => $column->getName(),
            $connection->createSchemaManager()->listTableColumns($table),
        ));
    }

    /**
     * The same names lower-cased, for comparisons: MySQL compares column names
     * case-insensitively, and DCA field names are camelCase.
     *
     * @return list<string>
     */
    public static function lowerNames(Connection $connection, string $table): array
    {
        return array_map('strtolower', self::names($connection, $table));
    }
}
