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
    private function connection(bool $table, int|false $existing, array $columns = []): Connection
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn($table);
        $schema->method('listTableColumns')->willReturn(array_map(static fn (string $c): Column => new Column($c, new StringType()), $columns));

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchOne')->with('SELECT id FROM tl_user WHERE username = ?', [CliUser::USERNAME])->willReturn($existing);

        return $connection;
    }

    public function testRunsOnlyWhileTheUserIsMissing(): void
    {
        $this->assertTrue((new CliUserMigration($this->connection(true, false)))->shouldRun());
        $this->assertFalse((new CliUserMigration($this->connection(true, 12)))->shouldRun());
        $this->assertFalse((new CliUserMigration($this->connection(false, false)))->shouldRun());
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
