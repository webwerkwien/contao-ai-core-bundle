<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\UndoReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UndoRestoreCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * The counterpart to `version:restore`, which never had one.
 *
 * Every delete in this bundle has written a `tl_undo` row since v0.2.8 — for a
 * cascade, one row covering the parent and everything under it — and nothing
 * could read those rows back. The safety net was being filled and never
 * emptied.
 *
 * The refusals are what these tests are mostly about. A restore writes rows
 * into live tables, and the two ways it can go wrong both end with the undo
 * entry still in place:
 *
 *  - the payload does not deserialize to an array (Contao abandons the same way)
 *  - an insert fails, overwhelmingly because the ID is taken again
 *
 * In both cases the entry must survive, or a failed restore also destroys the
 * only copy of what it failed to restore.
 */
class UndoCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function connection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    private function restorer(Connection $connection): UndoRestoreCommand
    {
        $cmd = new UndoRestoreCommand($this->createMock(ContaoFramework::class), $connection);
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }

    private function reader(Connection $connection): UndoReadCommand
    {
        return new UndoReadCommand($this->createMock(ContaoFramework::class), $connection);
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommand(object $command, array $input): array
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded, 'The command must answer with JSON.');

        return $decoded;
    }

    // --- the entry has to exist ---

    public function testRestoreReportsAMissingEntryRatherThanDoingNothingQuietly(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn(false);

        $out = $this->runCommand($this->restorer($connection), ['id' => '99999']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('99999', $out['message']);
    }

    public function testReadReportsAMissingEntry(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn(false);

        $out = $this->runCommand($this->reader($connection), ['id' => '99999']);

        $this->assertSame('error', $out['status']);
    }

    // --- an unusable payload must not consume the entry ---

    public function testAnUndeserializablePayloadIsRefusedAndTheEntryIsNotDeleted(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => 5, 'fromTable' => 'tl_news', 'query' => 'DELETE FROM tl_news', 'data' => 'not-serialized',
        ]);
        $connection->expects($this->never())->method('delete');

        $out = $this->runCommand($this->restorer($connection), ['id' => '5']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('payload', $out['message']);
    }

    public function testAnEmptyPayloadIsRefused(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => 5, 'fromTable' => 'tl_news', 'query' => '', 'data' => serialize([]),
        ]);
        $connection->expects($this->never())->method('delete');

        $out = $this->runCommand($this->restorer($connection), ['id' => '5']);

        $this->assertSame('error', $out['status']);
    }

    /**
     * The payload keys are table names taken straight from stored data. Anything
     * that is not a `tl_*` identifier never reaches a query.
     */
    public function testAPayloadKeyThatIsNotATableNameIsSkipped(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => 5, 'query' => '', 'data' => serialize(['DROP TABLE x' => [['id' => 1]]]),
        ]);
        $connection->expects($this->never())->method('insert');

        $out = $this->runCommand($this->restorer($connection), ['id' => '5']);

        $this->assertSame('ok', $out['status'], 'Nothing usable, but nothing dangerous either.');
        $this->assertSame(0, $out['rowsTotal']);
    }

    // --- reading the payload ---

    public function testReadSummarisesWhatWouldComeBack(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn([
            'id'        => 7,
            'fromTable' => 'tl_image_size',
            'query'     => 'DELETE FROM tl_image_size WHERE id=6',
            'pid'       => 1,
            'tstamp'    => 1756600000,
            'data'      => serialize([
                'tl_image_size'      => [['id' => 6, 'name' => 'Tourenbild']],
                'tl_image_size_item' => [['id' => 11], ['id' => 12]],
            ]),
        ]);
        $connection->method('fetchFirstColumn')->willReturn([]);

        $out = $this->runCommand($this->reader($connection), ['id' => '7']);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(3, $out['rowsTotal'], 'One size and its two variants.');
        $this->assertSame(1, $out['tables']['tl_image_size']['rows']);
        $this->assertSame([11, 12], $out['tables']['tl_image_size_item']['ids']);
    }

    /**
     * Contao re-inserts with the original ID, so an ID that is occupied again
     * makes the insert fail. Better said before the attempt than after it.
     */
    public function testReadReportsIdsThatAreTakenAgain(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => 7, 'query' => '', 'data' => serialize(['tl_news' => [['id' => 3]]]),
        ]);
        $connection->method('fetchFirstColumn')->willReturn([3]);

        $out = $this->runCommand($this->reader($connection), ['id' => '7']);

        $this->assertSame([3], $out['tables']['tl_news']['idsTaken']);
    }
}
