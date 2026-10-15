<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Theme;

use Contao\CoreBundle\Doctrine\Backup\Backup;
use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Doctrine\Backup\Config\CreateConfig;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\Theme\ExampleWebsite;

/**
 * The example website import (v1.9.0): what the preview reads from the file, the backup
 * before anything runs, and the administrators afterwards (Michael, 2026-10-15). Contao's
 * statement runner itself is stood in for here; it ran for real on c5 6.0.
 */
class ExampleWebsiteTest extends TestCase
{
    private string $dir;

    /** @var array<int, array<string, mixed>> the users table, by id */
    private array $users = [];

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/contao-ai-example-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/system/tmp', 0777, true);
        file_put_contents($this->dir . '/system/tmp/demo.sql', implode("\n", [
            'DROP TABLE IF EXISTS `tl_page`;',
            'CREATE TABLE `tl_page` (`id` int);',
            "INSERT INTO `tl_page` (`id`,`title`) VALUES (1,'Start');",
            "INSERT INTO `tl_user` (`id`,`username`,`admin`) VALUES (5,'demo',1);",
            '',
        ]));

        $this->users = [
            1 => ['id' => 1, 'username' => 'michael', 'admin' => 1, 'password' => 'hash'],
            2 => ['id' => 2, 'username' => 'contao-ai', 'admin' => 1, 'password' => ''],
            3 => ['id' => 3, 'username' => 'editor', 'admin' => 0, 'password' => 'hash2'],
        ];
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/system/tmp/*') ?: []);
        rmdir($this->dir . '/system/tmp');
        rmdir($this->dir . '/system');
        rmdir($this->dir);
    }

    /**
     * @param \Closure(array<int, array<string, mixed>>): array<int, array<string, mixed>> $import what the statements do to tl_user
     */
    private function service(?\Closure $import = null): ExampleWebsite
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['tl_page', 'tl_user', 'tl_news', 'other']);
        $schema->method('listTableColumns')->willReturnCallback(function (): array {
            return array_map(function (string $name): Column {
                $column = $this->createMock(Column::class);
                $column->method('getName')->willReturn($name);

                return $column;
            }, ['id', 'username', 'admin', 'password']);
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            fn (): array => array_values(array_filter($this->users, static fn (array $u): bool => 1 === (int) $u['admin'] && 'contao-ai' !== $u['username'])),
        );
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params): array|false {
            foreach ($this->users as $user) {
                if ($user['username'] === $params[0]) {
                    return $user;
                }
            }

            return false;
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params): int {
            $field = str_contains($sql, 'WHERE id') ? 'id' : 'username';

            return \count(array_filter($this->users, static fn (array $u): bool => (string) $u[$field] === (string) $params[0]));
        });
        $connection->method('delete')->willReturnCallback(function (string $table, array $criteria): int {
            $this->log[] = 'delete ' . $criteria['username'];
            $this->users = array_filter($this->users, static fn (array $u): bool => $u['username'] !== $criteria['username']);

            return 1;
        });
        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $row = [];
            foreach ($data as $column => $value) {
                $row[trim($column, '`')] = $value;
            }
            $row['id'] ??= max(array_keys($this->users) ?: [0]) + 1;
            $this->users[(int) $row['id']] = $row;
            $this->log[] = 'insert ' . $row['username'];

            return 1;
        });

        $backup = new Backup('backup__20261015090000.sql.gz');
        $backups = $this->createMock(BackupManager::class);
        $backups->method('createCreateConfig')->willReturn(new CreateConfig($backup));
        $backups->method('create')->willReturnCallback(function (): void {
            $this->log[] = 'backup';
        });

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('inBackEnd')->willReturnCallback(function (\Closure $work) use ($import): mixed {
            $this->log[] = 'statements';
            // Stands in for Contao's runner: what the file does to tl_user.
            $this->users = ($import ?? static fn (array $users): array => $users)($this->users);

            return null;
        });
        $callbacks->method('drainMessages')->willReturn([]);

        return new ExampleWebsite($this->createMock(ContaoFramework::class), $connection, $callbacks, $backups, $this->dir);
    }

    /** `data`: every table emptied, then the file's users. */
    private static function truncateAndAddDemo(): \Closure
    {
        return static fn (): array => [5 => ['id' => 5, 'username' => 'demo', 'admin' => 1, 'password' => 'demohash']];
    }

    public function testThePreviewReadsTheFile(): void
    {
        $preview = $this->service()->analyse('system/tmp/demo.sql', 'full');

        self::assertSame(4, $preview['statements']);
        self::assertSame(['tl_page', 'tl_user'], $preview['tables']);
        self::assertSame(1, $preview['user_rows']);
        self::assertSame(['michael'], $preview['existing_admins'], 'contao-ai is no administrator who logs in');
        self::assertTrue($preview['needs_admin_decision']);

        $data = $this->service()->analyse('system/tmp/demo.sql', 'data');
        self::assertSame(2, $data['statements'], 'only the INSERTs, as Contao runs them');
        self::assertSame(3, $data['truncates'], 'every tl_ table');
    }

    public function testWithoutAModeNothingIsDecidedForTheCaller(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no default');

        $this->service()->analyse('system/tmp/demo.sql', '');
    }

    public function testExistingAdminsAndImportedUsersNeedADecisionBeforeAnything(): void
    {
        try {
            $this->service()->import('system/tmp/demo.sql', 'data', null);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('--admins=both', $e->getMessage());
        }
        self::assertSame([], $this->log, 'no backup, no statement');
    }

    public function testExistingKeepsOnlyTheExistingAdmins(): void
    {
        $result = $this->service(self::truncateAndAddDemo())->import('system/tmp/demo.sql', 'data', 'existing');

        self::assertSame('backup__20261015090000.sql.gz', $result['backup']);
        self::assertSame(['backup', 'statements'], \array_slice($this->log, 0, 2), 'the backup comes first');
        self::assertSame(['kept' => ['michael'], 'removed' => ['demo'], 'imported' => []], $result['admins']);
        self::assertSame(['contao-ai', 'michael'], $this->usernames());
        self::assertSame('hash', $this->user('michael')['password'], 'the account comes back as it was');
    }

    public function testBothKeepsTheExistingNextToTheImported(): void
    {
        $result = $this->service(self::truncateAndAddDemo())->import('system/tmp/demo.sql', 'data', 'both');

        self::assertSame(['kept' => ['michael'], 'removed' => [], 'imported' => ['demo']], $result['admins']);
        self::assertSame(['contao-ai', 'demo', 'michael'], $this->usernames());
    }

    public function testOnTheSameUsernameTheExistingAccountWins(): void
    {
        $this->service(static fn (): array => [1 => ['id' => 1, 'username' => 'michael', 'admin' => 1, 'password' => 'from-the-file']])
            ->import('system/tmp/demo.sql', 'data', 'both');

        self::assertSame('hash', $this->user('michael')['password']);
    }

    public function testAnIdTakenByAnImportedUserGivesTheAccountANewOne(): void
    {
        $this->service(static fn (): array => [1 => ['id' => 1, 'username' => 'demo', 'admin' => 1, 'password' => 'x']])
            ->import('system/tmp/demo.sql', 'data', 'both');

        self::assertSame(['contao-ai', 'demo', 'michael'], $this->usernames());
        self::assertNotSame(1, (int) $this->user('michael')['id']);
    }

    public function testWithoutExistingAdminsTheImportedOnesSimplyArrive(): void
    {
        unset($this->users[1]);

        $result = $this->service(self::truncateAndAddDemo())->import('system/tmp/demo.sql', 'data', null);

        self::assertSame(['kept' => [], 'removed' => [], 'imported' => ['demo']], $result['admins']);
        self::assertSame(['contao-ai', 'demo'], $this->usernames(), 'contao-ai is put back');
    }

    public function testAFileOutsideSystemTmpIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('system/tmp/');

        $this->service()->analyse('../etc/demo.sql', 'full');
    }

    /**
     * @return list<string>
     */
    private function usernames(): array
    {
        $names = array_map(static fn (array $u): string => (string) $u['username'], array_values($this->users));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $username): array
    {
        foreach ($this->users as $user) {
            if ($user['username'] === $username) {
                return $user;
            }
        }

        self::fail("no user $username");
    }
}
