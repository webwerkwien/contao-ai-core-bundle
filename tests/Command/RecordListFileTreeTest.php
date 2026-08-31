<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * contao:record:list must return fileTree columns as UUID strings too.
 *
 * v0.2.15 fixed this for the model read path by putting
 * convertFileTreeFieldsToUuid() on AbstractModelReadCommand. RecordListCommand
 * extends AbstractReadCommand directly and so never inherited it — it reads
 * with plain DBAL and handed the raw 16 bytes to outputRecord(), whose
 * JSON_INVALID_UTF8_SUBSTITUTE turned each one into U+FFFD. The same destroyed
 * value the release notes claimed was gone, on the one read command that
 * accepts an *arbitrary* table and is therefore likeliest to be pointed at an
 * unfamiliar fileTree field. RecordListTool in the backend bundle passed it on
 * to the browser chat unchanged.
 *
 * Found 2026-08-31 while checking whether the CLI could reach the generic
 * table commands at all. Fixed by moving the conversion up one class, where
 * it belongs: it is driven by the DCA and takes (table, row), not a Model.
 */
class RecordListFileTreeTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private const UUID = '4e2fbd7a-6d3e-11ef-9b1a-0242ac120002';

    private function command(?Connection $connection = null): RecordListCommand
    {
        return new RecordListCommand(
            $this->createMock(ContaoFramework::class),
            $connection ?? $this->createMock(Connection::class),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_content']);
        parent::tearDown();
    }

    /**
     * The regression itself: the conversion has to be reachable from *this*
     * class. Seeding the DCA keeps Controller::loadDataContainer() — and with
     * it the need for a booted container — out of the way.
     */
    public function testTheConversionIsReachableFromRecordList(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields'] = ['singleSRC' => ['inputType' => 'fileTree']];

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_content', [
            'id'        => 5,
            'singleSRC' => StringUtil::uuidToBin(self::UUID),
        ]);

        $this->assertSame(self::UUID, $row['singleSRC']);
    }

    public function testTheConvertedRowSurvivesTheCommandsOwnEncoding(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields'] = ['singleSRC' => ['inputType' => 'fileTree']];

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_content', [
            'singleSRC' => StringUtil::uuidToBin(self::UUID),
        ]);

        // Same flags as AbstractReadCommand::outputRecord().
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertIsString($json);
        $this->assertStringNotContainsString("\u{FFFD}", $json, 'A substituted byte is a destroyed value.');
        $this->assertStringContainsString(self::UUID, $json);
    }

    /**
     * The behaviour end to end, through the command as a caller gets it.
     * Needs a container because RecordListCommand loads the DCA itself.
     */
    public function testTheCommandOutputCarriesTheUuidNotItsRawBytes(): void
    {
        $this->skipWithoutContaoContainer();

        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`'
        );
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 5, 'singleSRC' => StringUtil::uuidToBin(self::UUID)],
        ]);
        $connection->method('fetchOne')->willReturn(1);

        $tester = new CommandTester($this->command($connection));
        $tester->execute(['table' => 'tl_content', '--fields' => 'id,singleSRC']);

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString("\u{FFFD}", $display);

        $out = json_decode($display, true);
        $this->assertSame('ok', $out['status']);
        $this->assertSame(self::UUID, $out['results'][0]['singleSRC']);
    }
}
