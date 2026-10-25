<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Apply;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\TransactionalTables;

/**
 * Contao recommends InnoDB and does not require it. A MyISAM table does not roll
 * back, so all-or-nothing would be an empty promise there (v1.13.0).
 */
class TransactionalTablesTest extends TestCase
{
    /**
     * @param array<string, string> $engines
     */
    private function connection(array $engines): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAllKeyValue')
            ->with($this->stringContains('IN (?,?,?)'), ['tl_page', 'tl_content', 'tl_undo'])
            ->willReturn($engines);

        return $connection;
    }

    public function testInnoDbInAnyCasePasses(): void
    {
        $tables = TransactionalTables::nonTransactional(
            $this->connection(['tl_page' => 'InnoDB', 'tl_content' => 'innodb', 'tl_undo' => 'InnoDB']),
            ['tl_page', 'tl_content', 'tl_undo'],
        );

        $this->assertSame([], $tables);
    }

    public function testAnotherEngineIsNamedWithTheTable(): void
    {
        $tables = TransactionalTables::nonTransactional(
            $this->connection(['tl_page' => 'InnoDB', 'tl_content' => 'MyISAM', 'tl_undo' => 'Aria']),
            ['tl_page', 'tl_content', 'tl_undo'],
        );

        $this->assertSame(['tl_content (MyISAM)', 'tl_undo (Aria)'], $tables);
    }

    public function testAMissingTableIsNotThisChecksBusiness(): void
    {
        $tables = TransactionalTables::nonTransactional(
            $this->connection(['tl_page' => 'InnoDB']),
            ['tl_page', 'tl_content', 'tl_undo'],
        );

        $this->assertSame([], $tables);
    }
}
