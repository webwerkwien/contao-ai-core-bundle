<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A field whose widget stores an array takes an array — never a bare string.
 *
 * 🔴 **Measured on 2026-09-16 on c5 (Contao 5.7.13, core-bundle v0.11.0)**, in the
 * ConpAI 1.0 acceptance test: `layout update 25 --set modules=66` answered
 * `{"status":"ok"}` and stored the string `66`. The layout's module list was
 * gone, nothing reported it, and a page on that layout renders nothing.
 *
 * `tl_layout.modules` is a `moduleWizard`. The back end can only ever submit an
 * array for it; this write path goes around the widget. Same shape as `rgxp`,
 * `options` and booleans — a rule the widget enforces and `--set` loses.
 *
 * ## Which widgets
 *
 * Counted in the DCA files of a stock 5.7.13 with all optional bundles: eleven
 * input types store a serialized array without `eval.multiple`: `moduleWizard`,
 * `sectionWizard`, `rowWizard`, `tableWizard`, `optionWizard`, `metaWizard`,
 * `listWizard`, `keyValueWizard`, `imageSize`, `timePeriod`,
 * `rootPageDependentSelect`. Plus the two that have their own conversion
 * already — `inputUnit` and `cud`/`chmod` — which are checked too, as a net
 * under that conversion.
 *
 * `eval.multiple` is deliberately **not** in the list: with `eval.csv` such a
 * field stores a comma-separated string, and refusing it would be wrong.
 *
 * ## Why after the conversions
 *
 * `optionWizard` has a short form (`options="red|green"`) that is converted into
 * Contao's structure further down. Checked on the raw input, that valid call
 * would be refused. Checked afterwards, only a value that no conversion could
 * turn into an array is left — which is exactly the silent case.
 */
class StructuredValueRefusalTest extends TestCase
{
    private ImageSizeUpdateCommand $subject;

    protected function setUp(): void
    {
        $this->subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'modules'  => ['inputType' => 'moduleWizard'],
            'sections' => ['inputType' => 'sectionWizard'],
            'size'     => ['inputType' => 'imageSize'],
            'options'  => ['inputType' => 'optionWizard'],
            'title'    => ['inputType' => 'text'],
            'groups'   => ['inputType' => 'checkbox', 'eval' => ['multiple' => true, 'csv' => ',']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function refuse(array $fields): void
    {
        (new \ReflectionMethod($this->subject, 'refuseUnstructuredValues'))->invoke($this->subject, 'tl_test', $fields);
    }

    // --- refused ---

    public function testTheMeasuredCaseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('modules');

        $this->refuse(['modules' => '66']);
    }

    /**
     * @dataProvider notAnArray
     */
    public function testAValueThatIsNotASerializedArrayIsRefused(string $field, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($field);

        $this->refuse([$field => $value]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notAnArray(): array
    {
        return [
            'plain word'          => ['sections', 'header'],
            'comma list'          => ['size', '100,100,crop'],
            'serialized scalar'   => ['modules', serialize('66')],
            'broken serialized'   => ['modules', 'a:1:{i:0;s:9:"kaputt";}'],
            'json, not converted' => ['modules', '[{"mod":"66","col":"header"}]'],
        ];
    }

    public function testEveryOffenderIsNamedAtOnce(): void
    {
        try {
            $this->refuse(['modules' => '66', 'sections' => 'header']);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('modules', $e->getMessage());
            $this->assertStringContainsString('sections', $e->getMessage());
        }
    }

    // --- passes ---

    public function testContaosOwnFormPasses(): void
    {
        $this->refuse([
            'modules' => serialize([['mod' => '66', 'col' => 'header', 'enable' => '1']]),
            'size'    => serialize(['', '', '12']),
        ]);
        $this->addToAssertionCount(1);
    }

    public function testAnEmptyValueClearsAndPasses(): void
    {
        $this->refuse(['modules' => '', 'size' => '']);
        $this->addToAssertionCount(1);
    }

    public function testOtherFieldsAreUntouched(): void
    {
        // A csv multiple field stores a string on purpose; a text field is text.
        $this->refuse(['title' => '66', 'groups' => '1,2']);
        $this->addToAssertionCount(1);
    }

    public function testTheOptionWizardShortFormPassesOnceConverted(): void
    {
        $convert = new \ReflectionMethod($this->subject, 'convertOptionFields');
        $fields  = $convert->invoke($this->subject, 'tl_test', ['options' => 'red|green=Grün']);

        $this->refuse($fields);
        $this->addToAssertionCount(1);
    }

    // --- wiring: the check has to run, and after the conversions ---

    public function testConvertFieldsRunsTheCheckAfterTheConversions(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');

        $this->assertMatchesRegularExpression(
            '/function convertFields\(.*?convertOptionFields\(.*?convertMultipleFields\(.*?refuseUnstructuredValues\(.*?convertEmptyValues\(/s',
            $source,
            'refuseUnstructuredValues() must run inside convertFields(), after the conversions that build arrays',
        );
    }
}
