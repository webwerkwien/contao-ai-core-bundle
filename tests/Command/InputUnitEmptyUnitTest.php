<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * An empty unit is "no unit given", not a unit.
 *
 * 1. `{"value": "x", "unit": ""}` reset a stored h3 to the field's default h2:
 *    the empty string skipped "keep the record's unit" and fell to the default.
 * 2. `--set headline_unit=` on its own answered `ok` with `updated: []` — nothing
 *    to write, and nothing said so. It is refused now.
 */
class InputUnitEmptyUnitTest extends TestCase
{
    private ImageSizeUpdateCommand $subject;

    protected function setUp(): void
    {
        $this->subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'headline' => [
                'inputType' => 'inputUnit',
                'options'   => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convert(array $fields, ?object $record = null): array
    {
        return (new \ReflectionMethod($this->subject, 'convertInputUnitFields'))
            ->invoke($this->subject, 'tl_test', $fields, 'h2', $record);
    }

    private function stored(string $unit): object
    {
        return (object) ['headline' => serialize(['value' => 'Alt', 'unit' => $unit])];
    }

    public function testAnEmptyUnitInJsonKeepsTheStoredUnit(): void
    {
        $fields = $this->convert(['headline' => '{"value":"Neu","unit":""}'], $this->stored('h3'));

        $this->assertSame(['headline' => serialize(['value' => 'Neu', 'unit' => 'h3'])], $fields);
    }

    public function testAnEmptyCompanionNextToAValueKeepsTheStoredUnit(): void
    {
        $fields = $this->convert(['headline' => 'Neu', 'headline_unit' => ''], $this->stored('h4'));

        $this->assertSame(['headline' => serialize(['value' => 'Neu', 'unit' => 'h4'])], $fields);
    }

    public function testAnEmptyUnitInJsonOnCreateTakesTheDefault(): void
    {
        $this->assertSame(
            ['headline' => serialize(['value' => 'Neu', 'unit' => 'h2'])],
            $this->convert(['headline' => '{"value":"Neu","unit":""}']),
        );
    }

    public function testAnEmptyCompanionOnItsOwnIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('headline_unit is empty');

        $this->convert(['headline_unit' => ''], $this->stored('h3'));
    }

    public function testTheRefusalNamesTheAllowedUnits(): void
    {
        $this->expectExceptionMessage('allowed: h1, h2, h3, h4, h5, h6');

        $this->convert(['headline_unit' => ''], $this->stored('h3'));
    }

    public function testAnEmptyCompanionOnCreateIsRefused(): void
    {
        $this->expectExceptionMessage('headline_unit is empty');

        $this->convert(['headline_unit' => '']);
    }

    /**
     * The console passes strings only; a caller in code can pass null.
     */
    public function testANullCompanionOnItsOwnIsRefused(): void
    {
        $this->expectExceptionMessage('headline_unit is empty');

        $this->convert(['headline_unit' => null], $this->stored('h3'));
    }

    public function testANumberAsCompanionIsRefusedAsNoUnitName(): void
    {
        $this->expectExceptionMessage('headline_unit is not a unit name');

        $this->convert(['headline_unit' => 2], $this->stored('h3'));
    }

    public function testANullUnitInJsonKeepsTheStoredUnit(): void
    {
        $fields = $this->convert(['headline' => '{"value":"Neu","unit":null}'], $this->stored('h5'));

        $this->assertSame(['headline' => serialize(['value' => 'Neu', 'unit' => 'h5'])], $fields);
    }

    public function testWithoutOptionsTheRefusalListsNothing(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']['fields']['headline']['options']);

        try {
            $this->convert(['headline_unit' => ''], $this->stored('h3'));
            $this->fail('an empty companion on its own must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('For tl_test, headline_unit is empty: name a unit. Nothing was written.', $e->getMessage());
        }
    }
}
