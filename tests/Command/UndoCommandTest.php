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

    /** @return Connection&\PHPUnit\Framework\MockObject\MockObject */
    private function connection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    /** @param Connection&\PHPUnit\Framework\MockObject\MockObject $connection */
    private function restorer(Connection $connection): UndoRestoreCommand
    {
        $cmd = new UndoRestoreCommand($this->createMock(ContaoFramework::class), $connection);
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }


    /**
     * `columnsOf()` asks the schema manager which columns the table still has.
     * Without this the command stops at "table no longer exists" and never
     * reaches the transaction — which is exactly how the first version of
     * testAFailedInsertRollsEverythingBackAndKeepsTheEntry passed for the wrong
     * reason until the sibling test below caught it.
     *
     * @param list<string> $columns
     */
    private function givenColumns(Connection $connection, array $columns): void
    {
        $schema = $this->createMock(\Doctrine\DBAL\Schema\AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(array_fill_keys($columns, null));
        $connection->method('createSchemaManager')->willReturn($schema);
    }

    /** @param Connection&\PHPUnit\Framework\MockObject\MockObject $connection */
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

    // --- C-1: alles oder nichts ---

    /**
     * 🔴 C-1 (Audit 2026-09-02). Bis dahin lief der Restore ohne Transaktion:
     * Zeile für Zeile einfügen, bei einem Fehler abbrechen, den Undo-Eintrag
     * behalten — und die bereits geschriebenen Zeilen stehen lassen. Beim
     * nächsten Versuch kollidierten genau die, und der Eintrag war damit auch
     * für die Zeilen verloren, die nie ankamen.
     *
     * ⚠️ Contao selbst macht es bis heute so (`DC_Table::undo()`, 5.7): keine
     * Transaktion, `$error = true` und weiter. Für die Backend-Oberfläche mag
     * das reichen, weil ein Mensch das Ergebnis sieht. Dieser Befehl wird von
     * Skripten gerufen, die auf `status: error` erneut versuchen.
     */
    public function testAFailedInsertRollsEverythingBackAndKeepsTheEntry(): void
    {
        $this->skipWithoutContaoContainer();

        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => 5, 'fromTable' => 'tl_news', 'query' => 'DELETE FROM tl_news',
            'data' => serialize(['tl_news' => [['id' => 7, 'headline' => 'Erste'], ['id' => 8, 'headline' => 'Zweite']]]),
        ]);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $this->givenColumns($connection, ['id', 'headline']);

        // Der Callback wird ausgeführt wie im Ernstfall; der Insert wirft.
        $connection->method('transactional')->willReturnCallback(
            static function (callable $fn) {
                return $fn();
            }
        );
        $connection->method('insert')->willThrowException(
            new \RuntimeException('Duplicate entry 7 for key PRIMARY (1062)')
        );

        // Das ist die Zusicherung: der Eintrag wird NICHT gelöscht.
        $connection->expects($this->never())->method('delete');

        $out = $this->runCommand($this->restorer($connection), ['id' => '5']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('replayable', $out['message'], 'the answer must say the entry survived');
    }

    /**
     * Die Gegenprobe zur vorigen: ohne sie wäre der Test auch grün, wenn der
     * Befehl gar nichts täte. Diese Sitzung hat drei Scans produziert, die
     * nichts fanden und trotzdem bestanden.
     */
    public function testTheRestoreRunsInsideATransaction(): void
    {
        $this->skipWithoutContaoContainer();

        $connection = $this->connection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => 5, 'fromTable' => 'tl_news', 'query' => 'DELETE FROM tl_news',
            'data' => serialize(['tl_news' => [['id' => 7, 'headline' => 'Erste']]]),
        ]);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $this->givenColumns($connection, ['id', 'headline']);

        $connection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $fn) => $fn());

        $this->runCommand($this->restorer($connection), ['id' => '5']);
    }

    /**
     * Die Zusicherung, die auch ohne Contao-Container greift.
     *
     * Die beiden Verhaltenstests darüber brauchen `loadDataContainer()` und
     * damit einen gebooteten Container — im normalen `vendor/bin/phpunit` sind
     * sie übersprungen. Ein übersprungener Test schützt nichts, und der
     * Rückfall bestünde genau darin, die Transaktion wieder herauszunehmen.
     *
     * Dieselbe Begründung wie bei CreateCommandConversionTest: *"What actually
     * goes wrong is a missing line, and a missing line is what this looks for."*
     */
    public function testTheRestoreSourceStillWrapsTheInsertsInATransaction(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/UndoRestoreCommand.php');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        $this->assertStringContainsString(
            'transactional(',
            $code,
            'UndoRestoreCommand no longer wraps the inserts — a partial restore would leave the '
            . 'undo entry unusable for the rows that never arrived (C-1, 2026-09-02).',
        );

        $this->assertGreaterThan(1000, \strlen($code), 'the source scan read almost nothing');
    }
}
