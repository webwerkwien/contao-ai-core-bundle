<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Contao\Versions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * The manager hands creating and restoring to Contao's `Versions` (v1.4.0); these
 * tests pin what it passes in and what it checks around it.
 */
class VersionManagerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $globals = [];

    protected function setUp(): void
    {
        $this->globals = ['TL_DCA' => $GLOBALS['TL_DCA'] ?? null, 'BE_MOD' => $GLOBALS['BE_MOD'] ?? null];

        $GLOBALS['TL_DCA'] = [
            'tl_article'        => ['config' => ['enableVersioning' => true]],
            'tl_consho_product' => ['config' => ['enableVersioning' => true]],
            'tl_search'         => ['config' => []],
        ];
        $GLOBALS['BE_MOD'] = [
            'content' => [
                'article' => ['tables' => ['tl_article', 'tl_content']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->globals as $key => $value) {
            if (null === $value) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
    }

    private function manager(Connection $connection, ?Versions $versions = null): VersionManager
    {
        return new VersionManager(
            $connection,
            '/contao',
            null === $versions ? null : static fn (): Versions => $versions,
            static function (): void {},
        );
    }

    private function connectionWithTables(bool $exists): Connection
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn($exists);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);

        return $connection;
    }

    public function testAnyTableWhoseDcaKeepsVersionsIsAllowed(): void
    {
        $vm = $this->manager($this->connectionWithTables(true));

        $this->assertNull($vm->refusal('tl_article'));
        // An extension's table, which the fixed list of 24 refused until v1.4.0.
        $this->assertNull($vm->refusal('tl_consho_product'));
    }

    /**
     * @dataProvider refusals
     */
    public function testRefusalNamesTheReason(string $table, bool $exists, string $expected): void
    {
        $vm = $this->manager($this->connectionWithTables($exists));

        $this->assertStringContainsString($expected, (string) $vm->refusal($table));
        $this->assertFalse($vm->isAllowedTable($table));
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function refusals(): array
    {
        return [
            'SQL in the name'      => ['tl_user; DROP TABLE tl_user', true, 'Invalid table name'],
            'a schema-qualified'   => ['information_schema.tables', true, 'Invalid table name'],
            'empty'                => ['', true, 'Invalid table name'],
            'no DCA'               => ['tl_unknown_table', true, 'has no DCA'],
            'versioning off'       => ['tl_search', true, 'enableVersioning is off'],
            'DCA without a table'  => ['tl_consho_product', false, 'no database table'],
        ];
    }

    public function testLoadVersionDataReturnsFalseOnMissing(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(false);
        $this->assertFalse($this->manager($conn)->loadVersionData('tl_article', 1, 99));
    }

    public function testLoadVersionDataReturnsArrayOnValidData(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['data' => serialize(['id' => 1, 'title' => 'Test'])]);
        $result = $this->manager($conn)->loadVersionData('tl_article', 1, 1);
        $this->assertIsArray($result);
        $this->assertSame('Test', $result['title']);
    }

    public function testLoadVersionDataRejectsMaliciousObjects(): void
    {
        $conn = $this->createMock(Connection::class);
        // A serialised object comes back as __PHP_Incomplete_Class, never an array.
        $conn->method('fetchAssociative')->willReturn(['data' => serialize(new \stdClass())]);
        $this->assertFalse($this->manager($conn)->loadVersionData('tl_article', 1, 1));
    }

    public function testCreateVersionNamesTheUserAndTheEditUrlAndLetsContaoWrite(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->with('SELECT id FROM tl_user WHERE username = ?', ['j.wilson'])->willReturn('5');

        $versions = $this->createMock(Versions::class);
        // Operator passed explicitly: without it the username falls back to
        // $_SERVER[USER]/[USERNAME], which depends on the machine running the suite.
        $versions->expects($this->once())->method('setUsername')->with('j.wilson');
        $versions->expects($this->once())->method('setUserId')->with(5);
        // Not the module's first table, so `table=` — the form the back end stores.
        $versions->expects($this->once())->method('setEditUrl')->with('contao?do=article&table=tl_content&act=edit&id=1');
        $versions->expects($this->once())->method('create');

        $this->manager($conn, $versions)->createVersion('tl_content', 1, 'j.wilson');
    }

    public function testTheEditUrlLeavesOutTheTableOfAModulesFirstTableAndIsEmptyWithoutAModule(): void
    {
        $urls     = [];
        $versions = $this->createMock(Versions::class);
        $versions->method('setEditUrl')->willReturnCallback(static function (string $url) use (&$urls): void {
            $urls[] = $url;
        });

        $vm = $this->manager($this->createMock(Connection::class), $versions);
        $vm->createVersion('tl_article', 7, 'cli');
        $vm->createVersion('tl_consho_product', 3, 'cli');

        $this->assertSame(['contao?do=article&act=edit&id=7', ''], $urls);
    }

    public function testInitializeIsContaosInitialize(): void
    {
        $versions = $this->createMock(Versions::class);
        $versions->expects($this->once())->method('initialize');
        $versions->expects($this->never())->method('create');

        $this->manager($this->createMock(Connection::class), $versions)->initialize('tl_article', 1);
    }

    /**
     * v1.7.3 (showcase.conpai.eu): for a record without versions Contao's initialize()
     * runs create(true), which reads the edit URL from the request — none on the console.
     * Before initialize(), not after.
     */
    public function testInitializeSetsTheEditUrlFirst(): void
    {
        $calls    = [];
        $versions = $this->createMock(Versions::class);
        $versions->method('setEditUrl')->willReturnCallback(static function (string $url) use (&$calls): void {
            $calls[] = 'setEditUrl:' . $url;
        });
        $versions->method('initialize')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'initialize';
        });

        $this->manager($this->createMock(Connection::class), $versions)->initialize('tl_content', 4);

        $this->assertSame(['setEditUrl:contao?do=article&table=tl_content&act=edit&id=4', 'initialize'], $calls);
    }

    private function restoringConnection(int|false $active): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());
        $conn->method('fetchOne')->willReturn($active);

        return $conn;
    }

    public function testRestoreRunsContaosRestoreAndChecksTheActiveFlag(): void
    {
        $versions = $this->createMock(Versions::class);
        $versions->expects($this->once())->method('restore')->with(3);

        $this->manager($this->restoringConnection(1), $versions)->restore('tl_article', 1, 3);
    }

    public function testRestoreThatContaoSkippedSilentlyIsAnError(): void
    {
        $versions = $this->createMock(Versions::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Version 3 of tl_article:1 was not restored, nothing was changed: Contao did not restore it.');

        $this->manager($this->restoringConnection(0), $versions)->restore('tl_article', 1, 3);
    }

    public function testWhatRestoreThrowsComesBackAsOneErrorNamingTheVersion(): void
    {
        // On the console the failure path of Versions::restore() dies on the flash
        // message (there is no session); in the chat it throws a redirect. Either way.
        $cause    = new \LogicException('There is currently no session available.');
        $versions = $this->createMock(Versions::class);
        $versions->method('restore')->willThrowException($cause);

        try {
            $this->manager($this->restoringConnection(1), $versions)->restore('tl_article', 1, 3);
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Version 3 of tl_article:1 was not restored', $e->getMessage());
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    /**
     * Consho refuses to bring back versions of its order tables with an
     * `onrestore_version` callback (2026-10-10). That is a refusal, not a crash.
     */
    public function testARefusingCallbackStaysARefusal(): void
    {
        $cause    = new \InvalidArgumentException('Orders are not restored from versions.');
        $versions = $this->createMock(Versions::class);
        $versions->method('restore')->willThrowException($cause);

        try {
            $this->manager($this->restoringConnection(1), $versions)->restore('tl_article', 1, 3);
            $this->fail('expected a refusal');
        } catch (\Throwable $e) {
            $this->assertSame(\InvalidArgumentException::class, $e::class);
            $this->assertSame('Version 3 of tl_article:1 was not restored, nothing was changed: Orders are not restored from versions.', $e->getMessage());
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    public function testASubclassOfInvalidArgumentExceptionIsStillADefect(): void
    {
        $versions = $this->createMock(Versions::class);
        $versions->method('restore')->willThrowException(new class('broken') extends \InvalidArgumentException {});

        $this->expectException(\RuntimeException::class);

        $this->manager($this->restoringConnection(1), $versions)->restore('tl_article', 1, 3);
    }
}
