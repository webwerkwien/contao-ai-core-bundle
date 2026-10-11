<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Dca;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\VirtualFields;

/**
 * Virtual fields are read and written as DC_Table does in 5.7 and 6.0 (v1.6.0):
 * expanded from their target column, written back into it with its other values kept.
 */
class VirtualFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        VirtualFields::$supported = true;
        $GLOBALS['TL_DCA']['tl_probe_virtual']['fields'] = [
            'title'          => ['sql' => "varchar(255) NOT NULL default ''"],
            'jsonData'       => ['virtualTarget' => true, 'sql' => ['type' => 'json', 'notnull' => false]],
            'accountHolder'  => ['inputType' => 'text', 'targetColumn' => 'jsonData'],
            'cashOnDelivery' => ['inputType' => 'checkbox', 'targetColumn' => 'jsonData'],
            'withSql'        => ['targetColumn' => 'jsonData', 'sql' => "varchar(8) NOT NULL default ''"],
            'badTarget'      => ['inputType' => 'text', 'targetColumn' => 'title'],
        ];
    }

    protected function tearDown(): void
    {
        VirtualFields::$supported = null;
        unset($GLOBALS['TL_DCA']['tl_probe_virtual']);
    }

    public function testTheMapNamesOnlyFieldsWithoutSqlInAVirtualTarget(): void
    {
        $this->assertSame(['accountHolder' => 'jsonData', 'cashOnDelivery' => 'jsonData'], VirtualFields::map('tl_probe_virtual'));
    }

    public function testWithoutSupportThereAreNone(): void
    {
        VirtualFields::$supported = false;

        $this->assertSame([], VirtualFields::map('tl_probe_virtual'));
        $this->assertSame(['jsonData' => '{"a":1}'], VirtualFields::expand('tl_probe_virtual', ['jsonData' => '{"a":1}']));
    }

    public function testExpandReplacesTheTargetByItsFields(): void
    {
        $row = ['id' => 2, 'title' => 'Vorkasse', 'jsonData' => '{"accountHolder":"M. M.","cashOnDelivery":"","stray":"x"}'];

        $this->assertSame(
            ['id' => 2, 'title' => 'Vorkasse', 'accountHolder' => 'M. M.', 'cashOnDelivery' => ''],
            VirtualFields::expand('tl_probe_virtual', $row),
        );
        $this->assertSame(['id' => 3], VirtualFields::expand('tl_probe_virtual', ['id' => 3, 'jsonData' => 'not json']), 'invalid JSON is ignored, as by Contao');
    }

    public function testCombineKeepsTheOtherValuesOfTheTarget(): void
    {
        $stored = ['id' => 2, 'title' => 'Vorkasse', 'jsonData' => '{"accountHolder":"Alt","cashOnDelivery":"1"}'];

        $written = VirtualFields::combine('tl_probe_virtual', ['title' => 'Neu', 'accountHolder' => 'Neu GmbH'], $stored);

        $this->assertSame(['title' => 'Neu', 'jsonData' => '{"accountHolder":"Neu GmbH","cashOnDelivery":"1"}'], $written);
    }

    public function testCombineLeavesValuesWithoutVirtualFieldsAlone(): void
    {
        $this->assertSame(['title' => 'x'], VirtualFields::combine('tl_probe_virtual', ['title' => 'x'], ['jsonData' => '{"accountHolder":"A"}']));
    }

    public function testCombineOnANewRecordStartsAnEmptyTarget(): void
    {
        $this->assertSame(['jsonData' => '{"cashOnDelivery":"1"}'], VirtualFields::combine('tl_probe_virtual', ['cashOnDelivery' => '1'], []));
    }

    public function testAValueThatIsNoJsonIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tl_probe_virtual.jsonData');

        VirtualFields::combine('tl_probe_virtual', ['accountHolder' => "\xB1\x31"], []);
    }
}
