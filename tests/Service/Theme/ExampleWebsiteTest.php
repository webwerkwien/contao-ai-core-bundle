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
 * before anything runs, and the administrators afterwards. Contao's
 * statement runner itself is stood in for here; it ran for real on Contao 6.0.
 */
class ExampleWebsiteTest extends TestCase
{
    private string $dir;

    /** @var array<int, array<string, mixed>> the users table, by id */
    private array $users = [];

    /** @var list<string> */
    private array $log = [];

    /** tl_user dropped and not recreated: every lookup on it fails. */
    private bool $tableGone = false;

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
            1 => ['id' => 1, 'username' => 'webmaster', 'admin' => 1, 'password' => 'hash'],
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
            fn (string $sql): array => $this->tableGone ? throw new \RuntimeException('Base table tl_user not found') : array_values(array_filter(
                $this->users,
                static fn (array $u): bool => (str_contains($sql, 'admin = 1') === (1 === (int) $u['admin'])) && 'contao-ai' !== $u['username'],
            )),
        );
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params): array|false {
            if ($this->tableGone) {
                throw new \RuntimeException('Base table tl_user not found');
            }
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
        self::assertSame(['webmaster'], $preview['existing_admins'], 'contao-ai is no administrator who logs in');
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
        self::assertSame(['kept' => ['webmaster'], 'removed' => ['demo'], 'imported' => [], 'promoted' => [], 'renumbered' => []], $result['admins']);
        self::assertSame(['contao-ai', 'webmaster'], $this->usernames());
        self::assertSame('hash', $this->user('webmaster')['password'], 'the account comes back as it was');
    }

    public function testBothKeepsTheExistingNextToTheImported(): void
    {
        $result = $this->service(self::truncateAndAddDemo())->import('system/tmp/demo.sql', 'data', 'both');

        self::assertSame(['kept' => ['webmaster'], 'removed' => [], 'imported' => ['demo'], 'promoted' => [], 'renumbered' => []], $result['admins']);
        self::assertSame(['contao-ai', 'demo', 'webmaster'], $this->usernames());
    }

    public function testOnTheSameUsernameTheExistingAccountWins(): void
    {
        $this->service(static fn (): array => [1 => ['id' => 1, 'username' => 'webmaster', 'admin' => 1, 'password' => 'from-the-file']])
            ->import('system/tmp/demo.sql', 'data', 'both');

        self::assertSame('hash', $this->user('webmaster')['password']);
    }

    public function testAnIdTakenByAnImportedUserGivesTheAccountANewOne(): void
    {
        $this->service(static fn (): array => [1 => ['id' => 1, 'username' => 'demo', 'admin' => 1, 'password' => 'x']])
            ->import('system/tmp/demo.sql', 'data', 'both');

        self::assertSame(['contao-ai', 'demo', 'webmaster'], $this->usernames());
        self::assertNotSame(1, (int) $this->user('webmaster')['id']);
    }

    public function testWithoutExistingAdminsTheImportedOnesSimplyArrive(): void
    {
        unset($this->users[1]);

        $result = $this->service(self::truncateAndAddDemo())->import('system/tmp/demo.sql', 'data', null);

        self::assertSame(['kept' => [], 'removed' => [], 'imported' => ['demo'], 'promoted' => [], 'renumbered' => []], $result['admins']);
        self::assertSame(['contao-ai', 'demo'], $this->usernames(), 'contao-ai is put back');
    }

    /**
     * Before v1.9.0: a statement failing half-way left `data` with an empty tl_user
     * and no backup name in the answer.
     */
    public function testAFailureHalfWayStillPutsTheAdminsBackAndNamesTheBackup(): void
    {
        $service = $this->service(function (): array {
            $this->users = [];

            throw new \RuntimeException('Unknown column foo');
        });

        try {
            $service->import('system/tmp/demo.sql', 'data', 'existing');
            self::fail('expected the failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('backup__20261015090000.sql.gz', $e->getMessage());
            self::assertStringContainsString('Unknown column foo', $e->getMessage());
        }
        self::assertSame(['contao-ai', 'webmaster'], $this->usernames());
    }

    /**
     * A `full` import that dropped tl_user and stopped before recreating it
     * makes the putting back fail too — the backup's name must still be in the error.
     */
    public function testWhenThePuttingBackFailsTooTheBackupIsStillNamed(): void
    {
        $service = $this->service(function (): array {
            $this->tableGone = true;

            throw new \RuntimeException('Syntax error near CREATE');
        });

        try {
            $service->import('system/tmp/demo.sql', 'full', 'existing');
            self::fail('expected the failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('backup__20261015090000.sql.gz', $e->getMessage());
            self::assertStringContainsString('could NOT be put back', $e->getMessage());
            self::assertStringContainsString('Syntax error near CREATE', $e->getMessage());
        }
    }

    /** A row the import did not touch is not deleted and inserted again. */
    public function testAnUntouchedAdminIsLeftAlone(): void
    {
        $this->service()->import('system/tmp/demo.sql', 'data_no_truncate', 'both');

        self::assertNotContains('delete webmaster', $this->log);
        self::assertNotContains('insert webmaster', $this->log);
    }

    public function testARenumberedAccountIsNamed(): void
    {
        $result = $this->service(static fn (): array => [1 => ['id' => 1, 'username' => 'demo', 'admin' => 1, 'password' => 'x']])
            ->import('system/tmp/demo.sql', 'data', 'both');

        self::assertSame(['webmaster'], $result['admins']['renumbered']);
    }

    /**
     * `full` runs every line: an UPDATE on tl_user or a statement on another table is
     * named in the preview, and a change to tl_user asks for the decision (v1.14.0).
     */
    public function testThePreviewNamesWhatIsNoInsert(): void
    {
        file_put_contents($this->dir . '/system/tmp/demo.sql', implode("\n", [
            "INSERT INTO `tl_page` (`id`) VALUES (1);",
            "UPDATE `tl_user` SET `admin` = 1 WHERE `username` = 'editor';",
            'DROP TABLE `other`;',
            'SET NAMES utf8mb4;',
        ]));

        $preview = $this->service()->analyse('system/tmp/demo.sql', 'full');

        self::assertSame(0, $preview['user_rows']);
        self::assertSame(1, $preview['user_statements']);
        self::assertTrue($preview['needs_admin_decision']);
        self::assertSame(2, $preview['other_statement_count'], 'SET is no change');
        self::assertStringContainsString('UPDATE `tl_user`', $preview['other_statements'][0]);
        self::assertSame('DROP TABLE `other`;', $preview['other_statements'][1]);
    }

    /**
     * An editor the file made an administrator is an existing account: `existing` puts it
     * back as it was instead of deleting it, `both` names it (v1.14.0).
     */
    public function testAPromotedExistingAccountIsNotRemoved(): void
    {
        $promote = static function (array $users): array {
            $users[3]['admin'] = 1;
            $users[3]['password'] = 'changed';

            return $users;
        };

        $result = $this->service($promote)->import('system/tmp/demo.sql', 'full', 'existing');

        self::assertSame(['editor'], $result['admins']['promoted']);
        self::assertSame([], $result['admins']['removed']);
        self::assertSame(['contao-ai', 'editor', 'webmaster'], $this->usernames());
        self::assertSame(0, (int) $this->user('editor')['admin']);
        self::assertSame('hash2', $this->user('editor')['password'], 'the account comes back as it was');
    }

    /**
     * MySQL runs a versioned comment, and SET PASSWORD changes the site's database
     * account; what a dump sets for its own session stays out of the list.
     */
    public function testThePreviewNamesVersionedCommentsAndSetPassword(): void
    {
        file_put_contents($this->dir . '/system/tmp/demo.sql', implode("\n", [
            '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;',
            '/*!50503 SET character_set_client = utf8mb4 */;',
            '/*!40000 ALTER TABLE `tl_page` DISABLE KEYS */;',
            'SET FOREIGN_KEY_CHECKS=0;',
            "INSERT INTO `tl_page` (`id`) VALUES (1);",
            '/*!50001 DROP TABLE `tl_member` */;',
            "SET PASSWORD = 'x';",
        ]));

        $preview = $this->service()->analyse('system/tmp/demo.sql', 'full');

        self::assertSame(['/*!50001 DROP TABLE `tl_member` */;', "SET PASSWORD = 'x';"], $preview['other_statements']);
    }

    /**
     * A file that renames the account it promotes: found by its id, put back as it was.
     */
    public function testARenamedPromotedAccountIsPutBack(): void
    {
        $result = $this->service(static function (array $users): array {
            $users[3]['admin'] = 1;
            $users[3]['username'] = 'boss';

            return $users;
        })->import('system/tmp/demo.sql', 'full', 'existing');

        self::assertSame(['boss'], $result['admins']['promoted']);
        self::assertSame([], $result['admins']['removed']);
        self::assertSame(['contao-ai', 'editor', 'webmaster'], $this->usernames());
        self::assertSame(0, (int) $this->user('editor')['admin']);
    }

    public function testBothNamesAPromotedAccount(): void
    {
        $result = $this->service(static function (array $users): array {
            $users[3]['admin'] = 1;

            return $users;
        })->import('system/tmp/demo.sql', 'full', 'both');

        self::assertSame(['editor'], $result['admins']['promoted']);
        self::assertSame([], $result['admins']['imported']);
        self::assertSame(1, (int) $this->user('editor')['admin']);
    }

    public function testEmptyLinesAreAWarningInModeFull(): void
    {
        file_put_contents($this->dir . '/system/tmp/demo.sql', "/*!40101 SET NAMES utf8 */;\n\n-- a comment\nINSERT INTO `tl_page` (`id`) VALUES (1);\n");

        self::assertStringContainsString('2 empty or comment line(s)', $this->service()->analyse('system/tmp/demo.sql', 'full')['warnings'][0]);
        self::assertSame([], $this->service()->analyse('system/tmp/demo.sql', 'data')['warnings'], 'data runs only the INSERTs');
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
