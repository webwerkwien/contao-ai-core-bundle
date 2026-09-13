<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionRestoreCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\EntityClonerInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * The two write paths outside AbstractWriteCommand.
 *
 * `version restore` writes with a raw DBAL update; the back end invalidates in
 * `DC_Table::edit()` right after restoring a version. `record clone` creates a
 * finished record in one step, which the back end only reaches after copy and
 * save — so the new root is invalidated like a created one.
 */
class RestoreAndCloneCacheTest extends TestCase
{
    /**
     * @param list<string> $log
     */
    private function cacheTags(array &$log): CacheTagInvalidator
    {
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('recordChanged')->willReturnCallback(
            static function (string $table, int $id) use (&$log): void {
                $log[] = 'invalidate ' . $table . ':' . $id;
            }
        );
        $cacheTags->method('drainReport')->willReturn(['tags' => ['contao.sitemap.1'], 'warnings' => []]);

        return $cacheTags;
    }

    public function testARestoredVersionInvalidatesAfterItIsWrittenBack(): void
    {
        $log = [];

        $versions = $this->createMock(VersionManager::class);
        $versions->method('isAllowedTable')->willReturn(true);
        $versions->method('loadVersionData')->willReturn(['id' => 2, 'title' => 'Alt']);

        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(['id' => null, 'title' => null]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $connection->method('update')->willReturnCallback(
            static function (string $table, array $data, array $criteria) use (&$log): int {
                $log[] = 'update ' . trim($table, '`') . ':' . $criteria['id'];
                return 1;
            }
        );

        $command = new VersionRestoreCommand($this->createMock(ContaoFramework::class), $versions, $connection);
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setCacheTagInvalidator($this->cacheTags($log));

        $tester = new CommandTester($command);
        $tester->execute(['--table' => 'tl_page', '--id' => '2', '--ver' => '3']);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame(['update tl_page:2', 'invalidate tl_page:2'], $log);
        $this->assertSame(['contao.sitemap.1'], $answer['cacheTags']);
    }

    public function testACloneInvalidatesItsNewRootRecord(): void
    {
        $log = [];

        $cloner = $this->createMock(EntityClonerInterface::class);
        $cloner->method('supports')->willReturn(true);
        $cloner->method('clone')->willReturn(['id' => 7, 'table' => 'tl_page', 'count' => 3, 'ignored_modifications' => []]);

        $command = new RecordCloneCommand([$cloner]);
        $command->setCacheTagInvalidator($this->cacheTags($log));

        $tester = new CommandTester($command);
        $tester->execute(['--source-table' => 'tl_page', '--source-id' => '1']);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame(['invalidate tl_page:7'], $log);
        $this->assertSame(7, $answer['id']);
        $this->assertSame(['contao.sitemap.1'], $answer['cacheTags']);
    }
}
