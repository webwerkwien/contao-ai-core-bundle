<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\UndoRestoreCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * onundo runs per row inside the restore's transaction, and a refusal rolls it back (v1.8.1).
 *
 * Until v1.8.0 the callbacks ran after the commit with the command as `$dc`; a failure
 * was a warning on the console while the answer said `ok` and the undo entry was gone.
 * On c5 a restored Consho product came back without its list price and visibility.
 */
class UndoRestoreCallbacksTest extends TestCase
{
    use NeedsContaoContainerTrait;

    /**
     * @param list<string> $log
     */
    private function command(array &$log, ?\Throwable $refusal = null): UndoRestoreCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => 392, 'fromTable' => 'tl_news', 'query' => 'DELETE FROM tl_news',
            'data' => serialize(['tl_news' => [['id' => 7, 'headline' => 'Erste'], ['id' => 8, 'headline' => 'Zweite']]]),
        ]);
        // Quoted as DBAL quotes: the first version of this test returned the name as it
        // was, and passed while onundo got the insert's quoted keys — on c5 Consho read
        // $row['id'], found nothing and queued product 0 (2026-10-14).
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());
        $connection->method('insert')->willReturnCallback(static function (string $table, array $row) use (&$log): int {
            $log[] = 'insert ' . trim($table, '`') . ':' . $row['`id`'];

            return 1;
        });
        $connection->method('delete')->willReturnCallback(static function () use (&$log): int {
            $log[] = 'delete tl_undo';

            return 1;
        });

        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(['id' => null, 'headline' => null]);
        $connection->method('createSchemaManager')->willReturn($schema);

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('undo')->willReturnCallback(static function (string $table, array $row, int $undoId) use (&$log, $refusal): void {
            $log[] = 'onundo ' . $table . ':' . ($row['id'] ?? 'no id') . ' ' . $row['headline'] . ' undo ' . $undoId;

            if (null !== $refusal) {
                throw $refusal;
            }
        });

        $command = new UndoRestoreCommand($this->createMock(ContaoFramework::class), $connection);
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));
        $command->setDcaCallbacks($callbacks);

        return $command;
    }

    public function testOnundoRunsAfterEachInsertAndBeforeTheEntryGoes(): void
    {
        $this->skipWithoutContaoContainer();

        $log    = [];
        $tester = new CommandTester($this->command($log));
        $tester->execute(['id' => '392']);

        $this->assertSame([
            'insert tl_news:7',
            'onundo tl_news:7 Erste undo 392',
            'insert tl_news:8',
            'onundo tl_news:8 Zweite undo 392',
            'delete tl_undo',
        ], $log);
        $this->assertSame('ok', json_decode($tester->getDisplay(), true)['status']);
    }

    public function testARefusingCallbackRollsTheRestoreBackAndSaysWhy(): void
    {
        $this->skipWithoutContaoContainer();

        $log    = [];
        $tester = new CommandTester($this->command($log, new \InvalidArgumentException('tl_news.onundo of tl_undo:392 refused by X: Preis fehlt. Nothing was written.')));
        $tester->execute(['id' => '392']);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame('error', $answer['status']);
        $this->assertStringContainsString('rolled back', $answer['message']);
        $this->assertStringContainsString('Preis fehlt.', $answer['message']);
        $this->assertNotContains('delete tl_undo', $log, 'the entry stays replayable');
    }

    /**
     * A TypeError in a callback is a defect, not a refusal: rolled back as well, marked
     * with `exception`, and its own class named in the message (second Fable review).
     */
    public function testADefectInACallbackRollsBackAndIsMarkedAsOne(): void
    {
        $this->skipWithoutContaoContainer();

        $log    = [];
        $tester = new CommandTester($this->command($log, new \TypeError('Argument #3 ($dc) must be of type DataContainer')));
        $tester->execute(['id' => '392']);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame('error', $answer['status']);
        $this->assertArrayHasKey('exception', $answer);
        $this->assertStringContainsString('rolled back', $answer['message']);
        $this->assertStringContainsString('TypeError: Argument #3', $answer['message']);
        $this->assertNotContains('delete tl_undo', $log);
    }
}
