<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Apply;

use Doctrine\DBAL\Connection;

/**
 * Which of the given tables would not roll back (v1.13.0).
 *
 * Contao recommends InnoDB but does not require it, so `contao:ai:apply` asks
 * before promising all-or-nothing. Only InnoDB counts: MyISAM ignores
 * transactions, and Aria does not roll back either.
 */
final class TransactionalTables
{
    /**
     * @param list<string> $tables
     *
     * @return list<string> `table (ENGINE)` for each one that would not roll back
     */
    public static function nonTransactional(Connection $connection, array $tables): array
    {
        $engines = $connection->fetchAllKeyValue(
            \sprintf(
                'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s)',
                implode(',', array_fill(0, \count($tables), '?')),
            ),
            $tables,
        );

        $refused = [];

        foreach ($tables as $table) {
            $engine = (string) ($engines[$table] ?? '');

            if ('' !== $engine && 0 !== strcasecmp($engine, 'InnoDB')) {
                $refused[] = \sprintf('%s (%s)', $table, $engine);
            }
        }

        return $refused;
    }
}
