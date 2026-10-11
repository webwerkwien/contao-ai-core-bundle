<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Writer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\StringType;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\RecordCascadeCollector;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\ModelWriter;

/**
 * onbeforesubmit in the writer (v1.5.0): after the save_callbacks, before the write,
 * on every save that is not a move — where DC_Table::submit() runs it.
 *
 * 🎯 Until v1.5.0 every write of this bundle went past it. Contao itself hangs
 * StartStopValidationListener there (tl_page, tl_article, tl_content, tl_member,
 * tl_user and their groups), so `--set stop=…` before the start was stored; Consho
 * hangs a shop's currency, a cross-field rule and its gross-to-net conversion there.
 * Asked for by Consho, decided with the maintainer on 2026-10-11.
 */
class BeforeSubmitTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $dca = null;

    /** @var list<string> */
    private array $order = [];

    /** @var array<string, mixed>|null */
    private ?array $written = null;

    protected function setUp(): void
    {
        $this->dca = $GLOBALS['TL_DCA'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->dca) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $this->dca;
        }
    }

    /**
     * A table without a model, so the writer goes row by row and every write is visible.
     *
     * @param list<string> $columns
     */
    private function connection(array $columns, array $row = ['id' => 3, 'pid' => 1, 'title' => 'Alt']): Connection
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(array_map(static fn (string $c): Column => new Column($c, new StringType()), $columns));

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $connection->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());
        $connection->method('fetchAssociative')->willReturn($row);
        $connection->method('lastInsertId')->willReturn('42');
        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->order[] = 'insert ' . implode(',', array_keys($data));

            return 1;
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $data): int {
            $this->order[] = 'update ' . implode(',', array_keys($data));
            $this->written = $data;

            return 1;
        });

        return $connection;
    }

    private function writer(Connection $connection, DcaCallbacks $callbacks): ModelWriter
    {
        return new ModelWriter($connection, $this->createMock(VersionManager::class), $this->createMock(RecordCascadeCollector::class), null, $callbacks);
    }

    /** A table that declares onbeforesubmit and a virtual field without a column. */
    private function virtualAmount(): void
    {
        $GLOBALS['TL_DCA']['tl_contao_ai_price']['config']['onbeforesubmit_callback'] = [static fn (array $v): array => $v];
        $GLOBALS['TL_DCA']['tl_contao_ai_price']['fields']['amount'] = ['inputType' => 'text'];
    }

    public function testAnUpdateRunsItAfterTheSaveCallbacksOnTheStoredRowAndWritesWhatItReturns(): void
    {
        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('save')->willReturnCallback(function (string $t, int $id, array $fields): array {
            $this->order[] = 'save';

            return ['title' => 'Neu'];
        });
        $callbacks->expects($this->once())->method('beforeSubmit')
            ->with('tl_contao_ai_x', 3, ['title' => 'Neu'], ['id' => 3, 'pid' => 1, 'title' => 'Alt'])
            ->willReturnCallback(function (string $t, int $id, array $values): array {
                $this->order[] = 'beforesubmit';

                // Adds a field, as Consho's currency listener does.
                return $values + ['tstamp' => 1, 'currency' => 'EUR'];
            });
        $callbacks->method('submit')->willReturnCallback(function (): void { $this->order[] = 'onsubmit'; });

        $updated = $this->writer($this->connection(['id', 'pid', 'tstamp', 'title', 'currency']), $callbacks)
            ->update('tl_contao_ai_x', 3, ['title' => 'neu'], 'claude', true);

        $this->assertSame(['save', 'beforesubmit', 'update `tstamp`,`title`,`currency`', 'onsubmit'], $this->order);
        $this->assertSame('EUR', $this->written['`currency`']);
        // What was written, the added field included — the tstamp every write sets is not news.
        $this->assertSame(['title', 'currency'], $updated);
    }

    public function testItRunsWithoutTheSaveCallbacksFlagToo(): void
    {
        // The table's rules, like onsubmit: $saveCallbacks covers the fields' callbacks only.
        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->expects($this->never())->method('save');
        $callbacks->expects($this->once())->method('beforeSubmit')->willReturnArgument(2);

        $this->writer($this->connection(['id', 'tstamp', 'title']), $callbacks)->update('tl_contao_ai_x', 3, ['title' => 'Neu'], 'claude');
    }

    public function testAMoveIsNoSubmit(): void
    {
        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->expects($this->never())->method('beforeSubmit');

        $this->writer($this->connection(['id', 'pid', 'tstamp', 'title']), $callbacks)->update('tl_contao_ai_x', 3, ['pid' => 5], 'claude');
    }

    public function testAVirtualFieldReachesTheCallbackWhichTakesItOver(): void
    {
        $this->virtualAmount();

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->expects($this->once())->method('beforeSubmit')
            ->with('tl_contao_ai_price', 3, ['amount' => '12.00'])
            ->willReturn(['tstamp' => 1, 'net' => '10.00']);

        $updated = $this->writer($this->connection(['id', 'tstamp', 'net']), $callbacks)
            ->update('tl_contao_ai_price', 3, ['amount' => '12.00'], 'claude');

        $this->assertSame(['net'], $updated);
        $this->assertArrayNotHasKey('`amount`', $this->written);
    }

    public function testAVirtualFieldNoCallbackTookOverIsRefusedByNameNotDropped(): void
    {
        $this->virtualAmount();

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('beforeSubmit')->willReturnArgument(2);

        $connection = $this->connection(['id', 'tstamp', 'net']);
        $connection->expects($this->never())->method('update');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a column of tl_contao_ai_price: amount — no onbeforesubmit_callback took it over, so it would not be stored. Nothing was written.');

        $this->writer($connection, $callbacks)->update('tl_contao_ai_price', 3, ['amount' => '12.00'], 'claude');
    }

    public function testWithoutOnbeforesubmitAFieldWithoutColumnIsRefusedBeforeAnythingRuns(): void
    {
        // Nothing could take it over — refused as before v1.5.0, before any callback.
        $GLOBALS['TL_DCA']['tl_contao_ai_price']['fields']['amount'] = ['inputType' => 'text'];

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->expects($this->never())->method('save');
        $callbacks->expects($this->never())->method('beforeSubmit');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a column of tl_contao_ai_price: amount. Nothing was written.');

        $this->writer($this->connection(['id', 'tstamp', 'net']), $callbacks)->update('tl_contao_ai_price', 3, ['amount' => '12.00'], 'claude', true);
    }

    public function testATypoIsRefusedBeforeAnythingRunsEvenWithOnbeforesubmit(): void
    {
        // Only a field the DCA declares may wait for a callback.
        $this->virtualAmount();

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->expects($this->never())->method('beforeSubmit');

        $this->expectExceptionMessage('Not a column of tl_contao_ai_price: amonut. Nothing was written.');

        $this->writer($this->connection(['id', 'tstamp', 'net']), $callbacks)->update('tl_contao_ai_price', 3, ['amonut' => '12.00'], 'claude');
    }

    public function testACreateInsertsNoVirtualFieldAndWritesWhatTheCallbackReturns(): void
    {
        $this->virtualAmount();

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('create')->willReturnCallback(function (): void { $this->order[] = 'oncreate'; });
        $callbacks->expects($this->once())->method('beforeSubmit')
            ->with('tl_contao_ai_price', 42, ['title' => 'P', 'amount' => '12.00'])
            ->willReturnCallback(function (string $t, int $id, array $values): array {
                $this->order[] = 'beforesubmit';

                return ['tstamp' => 1, 'title' => 'P', 'net' => '10.00'];
            });
        $callbacks->method('submit')->willReturnCallback(function (): void { $this->order[] = 'onsubmit'; });

        $id = $this->writer($this->connection(['id', 'tstamp', 'title', 'net'], ['id' => 42, 'title' => 'P', 'net' => '0.00']), $callbacks)
            ->create('tl_contao_ai_price', ['title' => 'P', 'amount' => '12.00'], 'claude');

        $this->assertSame(42, $id);
        $this->assertSame(['insert `tstamp`,`title`', 'oncreate', 'beforesubmit', 'update `tstamp`,`net`', 'onsubmit'], $this->order);
        $this->assertSame('10.00', $this->written['`net`']);
    }
}
