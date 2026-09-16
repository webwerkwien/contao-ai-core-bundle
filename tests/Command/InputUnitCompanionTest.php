<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * Two gaps in `convertInputUnitFields()`, found in the review of the releases of
 * 2026-09-16 (core-bundle v0.16.0).
 *
 * 1. **A unit on its own did nothing.** `page update 5 --set headline_unit=h1`
 *    without `headline` consumed the companion key, wrote nothing and answered
 *    `ok` with `updated: []`. The unit now applies to the stored value, which stays.
 *
 * 2. **Associative unit options were refused.** The unit was compared with the
 *    option *values*, while `refuseInvalidOptions()` compares with the *keys* —
 *    Contao's reading of `['h1' => 'Heading 1']`. Core fields use plain lists,
 *    an extension may not.
 */
class InputUnitCompanionTest extends TestCase
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
            'size' => [
                'inputType' => 'inputUnit',
                'options'   => ['small' => 'Klein', 'large' => 'Groß'],
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

    // --- a unit on its own ---

    public function testAUnitAloneChangesTheUnitAndKeepsTheStoredValue(): void
    {
        $record = (object) ['headline' => serialize(['value' => 'Die fehlende Ebene', 'unit' => 'h2'])];

        $fields = $this->convert(['headline_unit' => 'h1'], $record);

        $this->assertSame(['headline' => serialize(['value' => 'Die fehlende Ebene', 'unit' => 'h1'])], $fields);
    }

    public function testAUnitAloneOnARecordWithoutValueWritesAnEmptyValue(): void
    {
        $fields = $this->convert(['headline_unit' => 'h3'], (object) ['headline' => '']);

        $this->assertSame(['headline' => serialize(['value' => '', 'unit' => 'h3'])], $fields);
    }

    public function testAUnitAloneOnCreateWritesAnEmptyValue(): void
    {
        $this->assertSame(['headline' => serialize(['value' => '', 'unit' => 'h4'])], $this->convert(['headline_unit' => 'h4']));
    }

    public function testAnUnknownUnitAloneIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('headline_unit=h9');

        $this->convert(['headline_unit' => 'h9'], (object) ['headline' => serialize(['value' => 'x', 'unit' => 'h2'])]);
    }

    // --- associative options ---

    public function testAUnitNamedByItsKeyIsAccepted(): void
    {
        $fields = $this->convert(['size' => '3', 'size_unit' => 'large']);

        $this->assertSame(['size' => serialize(['value' => '3', 'unit' => 'large'])], $fields);
    }

    public function testAUnitNamedByItsLabelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allowed: small, large');

        $this->convert(['size' => '3', 'size_unit' => 'Groß']);
    }

    public function testWithoutAUnitTheFirstKeyIsTheFallback(): void
    {
        // No default among the options: Contao's select would show the first entry.
        $fields = $this->convert(['size' => '3']);

        $this->assertSame(['size' => serialize(['value' => '3', 'unit' => 'small'])], $fields);
    }
}
