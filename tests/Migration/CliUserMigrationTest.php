<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\StringType;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Migration\CliUserMigration;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;

class CliUserMigrationTest extends TestCase
{
    /**
     * @param list<string> $columns
     */
    private function connection(bool $table, int|false $existing, array $columns = [], int $users = 1): Connection
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn($table);
        $schema->method('listTableColumns')->willReturn(array_map(static fn (string $c): Column => new Column($c, new StringType()), $columns));

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql, array $params = []) => match ($sql) {
            'SELECT id FROM tl_user WHERE username = ?' => [CliUser::USERNAME] === $params ? $existing : throw new \LogicException('wrong username'),
            'SELECT COUNT(*) FROM tl_user'              => (string) $users,
            default                                     => throw new \LogicException('unexpected query: ' . $sql),
        });

        return $connection;
    }

    public function testRunsOnlyWhileTheUserIsMissing(): void
    {
        $this->assertTrue((new CliUserMigration($this->connection(true, false)))->shouldRun());
        $this->assertFalse((new CliUserMigration($this->connection(true, 12)))->shouldRun());
        $this->assertFalse((new CliUserMigration($this->connection(false, false)))->shouldRun());
    }

    /**
     * On a fresh install contao:migrate runs before the administrator is
     * created. contao-ai would become user 1 — Contao's and the bundle's fallback
     * author — and an installer looking for an admin would find it.
     */
    public function testWaitsForTheFirstRealUser(): void
    {
        $this->assertFalse((new CliUserMigration($this->connection(true, false, [], 0)))->shouldRun());
        $this->assertTrue((new CliUserMigration($this->connection(true, false, [], 1)))->shouldRun());
    }

    public function testCreatesAnAdminThatCannotLogInWithTheColumnsTheInstallationHas(): void
    {
        $inserted   = null;
        // No dateAdded here: the row is cut to what the table has.
        $connection = $this->connection(true, false, ['id', 'tstamp', 'username', 'name', 'email', 'language', 'password', 'admin', 'disable', 'inherit']);
        $connection->method('insert')->willReturnCallback(static function (string $table, array $row) use (&$inserted): int {
            $inserted = [$table, $row];

            return 1;
        });

        $result = (new CliUserMigration($connection))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('tl_user', $inserted[0]);
        $this->assertSame(CliUser::USERNAME, $inserted[1]['username']);
        $this->assertSame(1, $inserted[1]['admin']);
        $this->assertSame(1, $inserted[1]['disable']);
        $this->assertSame('', $inserted[1]['password']);
        $this->assertArrayNotHasKey('dateAdded', $inserted[1]);
    }
}
