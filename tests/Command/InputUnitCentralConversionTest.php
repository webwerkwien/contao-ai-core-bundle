<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * Every write command turns an `inputUnit` field into its `{value, unit}` pair —
 * not only the two that remembered to.
 *
 * 🔴 **Measured on 2026-09-16 on c5 (Contao 5.7.13, core-bundle v0.11.0):**
 * `module create --type html --set headline=Vorher` answered `{"status":"ok"}` and
 * stored the bare string `Vorher` in `tl_module.headline`, an `inputUnit` column.
 * Only `content create` and `layout create` called `convertInputUnitFields()`;
 * every other create command wrote the raw value. The same shape `convertFields()`
 * was introduced for in v0.2.18: a conversion each command has to remember is a
 * conversion some command forgets.
 *
 * ## The default unit comes from the field's SQL default
 *
 * That is where Contao keeps it, and it is what the back end writes when a record
 * is created: `tl_module.headline` declares
 * `default 'a:2:{s:5:"value";s:0:"";s:4:"unit";s:2:"h2";}'`. Without one — the
 * layout widths declare `default ''` — the first option is taken, which for
 * `tl_layout` is `px`, the value `layout create` has always used.
 *
 * Both SQL forms are read: the string of Contao 5 and the array
 * (`['type' => 'string', 'default' => …]`) Contao 6 increasingly uses.
 *
 * ## Already converted stays converted
 *
 * The update commands and `content create` convert before `convertFields()` runs,
 * so the central call sees a finished pair. It must leave it alone — otherwise the
 * serialized string would itself be wrapped as a value.
 */
class InputUnitCentralConversionTest extends TestCase
{
    private ImageSizeUpdateCommand $subject;

    protected function setUp(): void
    {
        $this->subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            // tl_module.headline in 5.7.13, verbatim.
            'headline' => [
                'inputType' => 'inputUnit',
                'options'   => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                'sql'       => "varchar(255) NOT NULL default 'a:2:{s:5:\"value\";s:0:\"\";s:4:\"unit\";s:2:\"h2\";}'",
            ],
            // The array form.
            'subline' => [
                'inputType' => 'inputUnit',
                'options'   => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                'sql'       => ['type' => 'string', 'length' => 255, 'default' => serialize(['value' => '', 'unit' => 'h3'])],
            ],
            // tl_layout.width in 5.7.13: no unit in the default.
            'width' => [
                'inputType' => 'inputUnit',
                'options'   => ['px', '%', 'em', 'rem', 'vw', 'vh'],
                'sql'       => "varchar(255) NOT NULL default ''",
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    /**
     * The central call: no default unit given.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convert(array $fields): array
    {
        return (new \ReflectionMethod($this->subject, 'convertInputUnitFields'))->invoke($this->subject, 'tl_test', $fields);
    }

    public function testTheMeasuredCaseBecomesAPairWithTheSqlDefaultUnit(): void
    {
        $out = $this->convert(['headline' => 'Vorher']);

        $this->assertSame(serialize(['value' => 'Vorher', 'unit' => 'h2']), $out['headline']);
    }

    public function testTheArraySqlFormIsRead(): void
    {
        $out = $this->convert(['subline' => 'Unterzeile']);

        $this->assertSame(serialize(['value' => 'Unterzeile', 'unit' => 'h3']), $out['subline']);
    }

    public function testWithoutAUnitInTheDefaultTheFirstOptionIsTaken(): void
    {
        $out = $this->convert(['width' => '90']);

        $this->assertSame(serialize(['value' => '90', 'unit' => 'px']), $out['width']);
    }

    public function testACompanionUnitStillWins(): void
    {
        $out = $this->convert(['headline' => 'Titel', 'headline_unit' => 'h1']);

        $this->assertSame(serialize(['value' => 'Titel', 'unit' => 'h1']), $out['headline']);
        $this->assertArrayNotHasKey('headline_unit', $out);
    }

    public function testAFinishedPairIsLeftAlone(): void
    {
        $pair = serialize(['value' => 'Schon fertig', 'unit' => 'h4']);

        $this->assertSame($pair, $this->convert(['headline' => $pair])['headline']);
    }

    public function testConvertingTwiceChangesNothing(): void
    {
        $once  = $this->convert(['headline' => 'Titel', 'headline_unit' => 'h5']);
        $twice = $this->convert($once);

        $this->assertSame($once, $twice);
    }

    public function testAnExplicitDefaultFromACommandIsStillHonoured(): void
    {
        $out = (new \ReflectionMethod($this->subject, 'convertInputUnitFields'))
            ->invoke($this->subject, 'tl_test', ['headline' => 'Titel'], 'h1');

        $this->assertSame(serialize(['value' => 'Titel', 'unit' => 'h1']), $out['headline']);
    }

    // --- wiring ---

    public function testConvertFieldsConvertsInputUnitsBeforeAnyCheck(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');

        // Before refuseUnknownFields(): the companion "<field>_unit" key is not a
        // column and has to be consumed first.
        $this->assertMatchesRegularExpression(
            '/function convertFields\(.*?convertInputUnitFields\(.*?refuseUnknownFields\(/s',
            $source,
            'convertInputUnitFields() must run inside convertFields(), before refuseUnknownFields()',
        );
    }
}
