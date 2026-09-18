<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageReadCommand;

/**
 * A field Contao stores as a serialized array is read as an array — and written as one.
 *
 * 🟡 **Measured on 2026-09-16 on c5 (Contao 5.7.13)**, ConpAI 1.0 acceptance test:
 *
 * | read | answered |
 * |---|---|
 * | `content read 647` → `headline` | `{"value": "Die fehlende Ebene", "unit": "h2"}` |
 * | `layout read 25` → `width`, `modules`, `framework` | `"a:2:{s:5:\"value\";…}"` — the raw PHP serialization |
 * | `record list tl_content --fields headline` | the raw serialization again |
 *
 * `content read` unpacked `headline` by hand; nothing else unpacked anything. A caller
 * had to parse PHP serialization — the CLI did so with a regular expression — and could
 * not write back what it had read, because writing wanted the serialized string.
 *
 * Decided on 2026-09-16, before 1.0 fixes the contract: **every read answers these
 * fields as arrays**, and **writing accepts them as JSON**, so what was read can be
 * written back unchanged.
 *
 * Covered: the input types that store a serialized array (wizards, `imageSize`,
 * `inputUnit`, `timePeriod`, `rootPageDependentSelect`, `cud`, `chmod`) and
 * `eval.multiple` fields — except with `eval.csv`, which store a comma-separated string.
 * `fileTree` keeps its own conversion to UUID strings.
 */
class StructuredFieldRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'modules'  => ['inputType' => 'moduleWizard'],
            'width'    => ['inputType' => 'inputUnit', 'options' => ['px', 'vw']],
            'size'     => ['inputType' => 'imageSize', 'eval' => ['rgxp' => 'natural']],
            'groups'   => ['inputType' => 'checkbox', 'eval' => ['multiple' => true]],
            'classes'  => ['inputType' => 'checkbox', 'eval' => ['multiple' => true, 'csv' => ',']],
            'title'    => ['inputType' => 'text'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    // --- reading ---

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function read(array $row): array
    {
        return (new PageReadCommand($this->createMock(ContaoFramework::class)))->convertStructuredFieldsForRead('tl_test', $row);
    }

    public function testAWizardFieldIsReadAsAnArray(): void
    {
        $modules = [['mod' => '66', 'col' => 'header', 'enable' => '1'], ['mod' => '0', 'col' => 'main', 'enable' => '1']];

        $this->assertSame($modules, $this->read(['modules' => serialize($modules)])['modules']);
    }

    public function testAnInputUnitIsReadAsValueAndUnit(): void
    {
        $this->assertSame(['value' => '90', 'unit' => 'vw'], $this->read(['width' => serialize(['value' => '90', 'unit' => 'vw'])])['width']);
    }

    public function testAMultipleFieldIsReadAsAList(): void
    {
        $this->assertSame(['1', '3'], $this->read(['groups' => serialize(['1', '3'])])['groups']);
    }

    public function testACsvFieldStaysAString(): void
    {
        $this->assertSame('a,b', $this->read(['classes' => 'a,b'])['classes']);
    }

    public function testATextFieldIsNeverUnpacked(): void
    {
        $text = serialize(['looks' => 'serialized']);

        $this->assertSame($text, $this->read(['title' => $text])['title']);
    }

    public function testAnEmptyValueStaysEmpty(): void
    {
        $this->assertSame('', $this->read(['modules' => ''])['modules']);
        $this->assertNull($this->read(['modules' => null])['modules']);
    }

    // --- writing back ---

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function write(array $fields): array
    {
        $command = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        return (new \ReflectionMethod($command, 'convertJsonStructuredFields'))->invoke($command, 'tl_test', $fields);
    }

    public function testWhatWasReadCanBeWrittenBackAsJson(): void
    {
        $modules = [['mod' => '66', 'col' => 'header', 'enable' => '1']];

        $this->assertSame(serialize($modules), $this->write(['modules' => json_encode($modules)])['modules']);
    }

    /**
     * `--set pagemounts=1,2` stores integers, as Contao's page picker does. As JSON the
     * same list was stored as strings until v0.16.0, so a read-and-write-back changed
     * the stored form (review 2026-09-16).
     */
    public function testAPageTreeListIsStoredAsIntegersFromJsonToo(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['pagemounts'] = ['inputType' => 'pageTree', 'eval' => ['multiple' => true]];

        $this->assertSame(serialize([1, 2]), $this->write(['pagemounts' => '["1",2]'])['pagemounts']);
    }

    public function testAnImageSizeCanBeGivenAsJson(): void
    {
        $this->assertSame(serialize(['', '', '6']), $this->write(['size' => '["","","6"]'])['size']);
    }

    public function testASerializedValueIsLeftAlone(): void
    {
        $serialized = serialize([['mod' => '66', 'col' => 'header', 'enable' => '1']]);

        $this->assertSame($serialized, $this->write(['modules' => $serialized])['modules']);
    }

    public function testAnInputUnitAndTextAreNotTouchedHere(): void
    {
        // inputUnit JSON has its own conversion ({"unit","value"}); text is text.
        $out = $this->write(['width' => '{"value":"90","unit":"vw"}', 'title' => '["not","json-for-me"]']);

        $this->assertSame('{"value":"90","unit":"vw"}', $out['width']);
        $this->assertSame('["not","json-for-me"]', $out['title']);
    }

    /**
     * Until v0.26.0 invalid JSON was left for refuseUnstructuredValues(). That covers the
     * wizards, but a list or table field went through the comma conversion first:
     * `[Eins,Zwei,Drei]` — what Windows PowerShell leaves of a quoted JSON list — was
     * stored as `['[Eins', 'Zwei', 'Drei]']` and answered ok (agent test, 2026-09-18).
     */
    public function testInvalidJsonIsRefusedBeforeAnyConversion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not valid JSON for tl_test: modules=[{"mod":');

        $this->write(['modules' => '[{"mod":']);
    }

    public function testJsonWhoseQuotesTheShellDroppedIsRefused(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['listitems'] = ['inputType' => 'listWizard'];
        $GLOBALS['TL_DCA']['tl_test']['fields']['tableitems'] = ['inputType' => 'tableWizard'];

        foreach (['listitems' => '[Eins,Zwei,Drei]', 'tableitems' => '[[A,B],[C,D]]'] as $field => $value) {
            try {
                $this->write([$field => $value]);
                $this->fail($field . ' was accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($field . '=' . $value, $e->getMessage());
                $this->assertStringContainsString('Nothing was written', $e->getMessage());
            }
        }
    }

    public function testTheCommaFormAndValidJsonStillPass(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['listitems'] = ['inputType' => 'listWizard'];

        $this->assertSame('Eins,Zwei', $this->write(['listitems' => 'Eins,Zwei'])['listitems']);
        $this->assertSame(serialize(['Eins', 'Zwei']), $this->write(['listitems' => '["Eins","Zwei"]'])['listitems']);
        // A text field is never JSON-checked, whatever it starts with.
        $this->assertSame('[Hinweis] Text', $this->write(['title' => '[Hinweis] Text'])['title']);
    }

    // --- wiring ---

    public function testEveryReadPathAndTheWritePathUseIt(): void
    {
        $src = __DIR__ . '/../../src/Command/';

        $this->assertStringContainsString('convertStructuredFieldsForRead(', (string) file_get_contents($src . 'AbstractModelReadCommand.php'));
        $this->assertStringContainsString('convertStructuredFieldsForRead(', (string) file_get_contents($src . 'RecordListCommand.php'));
        $this->assertMatchesRegularExpression(
            '/function convertFields\(.*?convertJsonStructuredFields\(.*?refuseUnknownFields\(/s',
            (string) file_get_contents($src . 'AbstractWriteCommand.php'),
        );
    }
}
