<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\UndoRestoreCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * 🔴 Here this bundle deliberately does better than the back end.
 *
 * `DC_Table::undo()` calls `invalidateCacheTags()` on itself — and its table at
 * that point is tl_undo. The restored records' tags are never invalidated and
 * their `oninvalidate_cache_tags_callback`s never run (5.3, 5.7, 6.0). Seen on
 * c5 on 2026-09-13 from the Consho project: a restored price left the cached
 * sitemap without its product. Consho works around it with onundo callbacks,
 * which it keeps for the back end.
 *
 * Decided 2026-09-13: every restored row is invalidated, after the commit.
 */
class UndoRestoreCacheTest extends TestCase
{
    use NeedsContaoContainerTrait;

    public function testEveryRestoredRecordIsInvalidatedAndTlUndoIsNot(): void
    {
        $this->skipWithoutContaoContainer();

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => 5, 'fromTable' => 'tl_news', 'query' => 'DELETE FROM tl_news',
            'data' => serialize(['tl_news' => [['id' => 7, 'headline' => 'Erste'], ['id' => 8, 'headline' => 'Zweite']]]),
        ]);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());
        $connection->method('insert')->willReturn(1);
        $connection->method('delete')->willReturn(1);

        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(['id' => null, 'headline' => null]);
        $connection->method('createSchemaManager')->willReturn($schema);

        $collected   = [];
        $invalidated = [];
        $cacheTags   = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('collect')->willReturnCallback(
            static function (string $table, int $id) use (&$collected): array {
                $collected[] = $table . ':' . $id;
                return ['contao.db.' . $table . '.' . $id];
            }
        );
        $cacheTags->method('invalidate')->willReturnCallback(
            static function (array $tags) use (&$invalidated): void {
                $invalidated = [...$invalidated, ...$tags];
            }
        );
        $cacheTags->method('drainReport')->willReturn(['tags' => [], 'warnings' => []]);

        $command = new UndoRestoreCommand($this->createMock(ContaoFramework::class), $connection);
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));
        $command->setCacheTagInvalidator($cacheTags);

        (new CommandTester($command))->execute(['id' => '5']);

        $this->assertSame(['tl_news:7', 'tl_news:8'], $collected);
        $this->assertSame(['contao.db.tl_news.7', 'contao.db.tl_news.8'], $invalidated);
    }

    /**
     * Runs without a container, so the ordering is guarded in every plain
     * `vendor/bin/phpunit`: the tags are collected after the transaction. A
     * record collected inside it would be read before the commit, and a
     * rollback would have invalidated a restore that never happened.
     */
    public function testTheRestoreInvalidatesOnlyAfterItsTransaction(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/UndoRestoreCommand.php');

        $transaction = strpos($source, '->transactional(');
        $collect     = strpos($source, '->collect(');

        $this->assertNotFalse($transaction);
        $this->assertNotFalse($collect, 'UndoRestoreCommand never collects cache tags for what it restores.');
        $this->assertGreaterThan($transaction, $collect);
    }
}
