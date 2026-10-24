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
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
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
        // First thing in a run: an earlier command's report is forgotten (v1.4.1).
        $cacheTags->method('reset')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'reset';
        });

        return $cacheTags;
    }

    public function testARestoredVersionInvalidatesAfterItIsWrittenBack(): void
    {
        $log = [];

        $versions = $this->createMock(VersionManager::class);
        $versions->method('refusal')->willReturn(null);
        $versions->method('loadVersionData')->willReturn(['id' => 2, 'title' => 'Alt']);
        $versions->method('restore')->willReturnCallback(
            static function (string $table, int $id, int $version) use (&$log): void {
                $log[] = 'restore ' . $table . ':' . $id . ' v' . $version;
            }
        );

        $command = new VersionRestoreCommand($this->createMock(ContaoFramework::class), $versions);
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setCacheTagInvalidator($this->cacheTags($log));

        $tester = new CommandTester($command);
        $tester->execute(['--table' => 'tl_page', '--id' => '2', '--ver' => '3']);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame(['reset', 'restore tl_page:2 v3', 'invalidate tl_page:2'], $log);
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

        $this->assertSame(['reset', 'invalidate tl_page:7'], $log);
        $this->assertSame(7, $answer['id']);
        $this->assertSame(['contao.sitemap.1'], $answer['cacheTags']);
    }

    /**
     * v1.6.0: an oncopy callback that refuses tells why in its messages,
     * and the clone's own error answer has to carry them.
     */
    public function testACloneRefusedByACallbackCarriesItsMessages(): void
    {
        $cloner = $this->createMock(EntityClonerInterface::class);
        $cloner->method('supports')->willReturn(true);
        $cloner->method('clone')->willThrowException(new \InvalidArgumentException('tl_page.oncopy refused. Nothing was written.'));

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('drainMessages')->willReturnOnConsecutiveCalls([], [['type' => 'error', 'text' => 'Kopie nicht erlaubt.']]);

        $command = new RecordCloneCommand([$cloner]);
        $command->setDcaCallbacks($callbacks);

        $tester = new CommandTester($command);
        $tester->execute(['--source-table' => 'tl_page', '--source-id' => '1']);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame('error', $answer['status']);
        $this->assertSame([['type' => 'error', 'text' => 'Kopie nicht erlaubt.']], $answer['messages']);
    }
}
