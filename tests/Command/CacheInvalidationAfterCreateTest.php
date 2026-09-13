<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * The create path: 22 commands save a model and then call createVersion().
 *
 * That call is the one place every create passes after its record exists, so
 * the cache is invalidated there. The structural test below keeps it true — a
 * create command that versioned before saving would invalidate before the
 * write, and a request in between would cache the old state again.
 */
class CacheInvalidationAfterCreateTest extends TestCase
{
    /**
     * @param list<string> $order
     */
    private function command(CacheTagInvalidator $cacheTags, array &$order): AbstractWriteCommand
    {
        $command = new class ('test:create') extends AbstractWriteCommand {
            protected function doExecute(array $fields): int
            {
                $this->createVersion('tl_page', 2);
                $this->outputSuccess(['id' => 2]);

                return 0;
            }
        };

        $versions = $this->createMock(VersionManager::class);
        $versions->method('createVersion')->willReturnCallback(
            static function (string $table, int $id) use (&$order): void {
                $order[] = 'version ' . $table . ':' . $id;
            }
        );

        $command->setVersionManager($versions);
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setCacheTagInvalidator($cacheTags);

        return $command;
    }

    public function testACreatedRecordInvalidatesItsCacheTagsAfterItIsVersioned(): void
    {
        $order     = [];
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('recordChanged')->willReturnCallback(
            static function (string $table, int $id) use (&$order): void {
                $order[] = 'invalidate ' . $table . ':' . $id;
            }
        );

        (new CommandTester($this->command($cacheTags, $order)))->execute([]);

        $this->assertSame(['version tl_page:2', 'invalidate tl_page:2'], $order);
    }

    /**
     * What a caller sees: the tags that went out, and — only when something
     * failed — why a part of the cache may still be stale.
     */
    public function testTheAnswerNamesTheInvalidatedTagsAndAnyWarning(): void
    {
        $order     = [];
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('drainReport')->willReturn([
            'tags'     => ['contao.db.tl_page.2', 'contao.sitemap.1'],
            'warnings' => ['tl_page.2: oninvalidate_cache_tags_callback closure failed'],
        ]);

        $tester = new CommandTester($this->command($cacheTags, $order));
        $tester->execute([]);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame('ok', $answer['status']);
        $this->assertSame(['contao.db.tl_page.2', 'contao.sitemap.1'], $answer['cacheTags']);
        $this->assertSame(['tl_page.2: oninvalidate_cache_tags_callback closure failed'], $answer['cacheWarnings']);
    }

    public function testWithoutWarningsTheAnswerCarriesNoWarningKey(): void
    {
        $order     = [];
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('drainReport')->willReturn(['tags' => ['contao.db.tl_page.2'], 'warnings' => []]);

        $tester = new CommandTester($this->command($cacheTags, $order));
        $tester->execute([]);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame(['contao.db.tl_page.2'], $answer['cacheTags']);
        $this->assertArrayNotHasKey('cacheWarnings', $answer);
    }

    /**
     * Guards the invariant the hook relies on, in the source rather than in a
     * comment: every create command saves first, then versions.
     */
    public function testEveryCreateCommandSavesBeforeItVersions(): void
    {
        $files = glob(__DIR__ . '/../../src/Command/*CreateCommand.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source  = (string) file_get_contents($file);
            $version = strpos($source, '$this->createVersion(');

            if (false === $version) {
                continue;
            }

            $save = strpos($source, '->save()');

            $this->assertNotFalse($save, basename($file) . ' versions a record it never saves.');
            $this->assertLessThan($version, $save, basename($file) . ' versions before it saves.');
        }
    }
}
