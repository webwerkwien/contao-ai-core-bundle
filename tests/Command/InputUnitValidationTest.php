<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * An `inputUnit` field is two values in one column, and each half has its own rule.
 *
 * 🔴 **Measured on 2026-09-16 against 5.7.13, core-bundle v0.10.0**, in the
 * ConpAI 1.0 acceptance test:
 *
 * | `--set` | answer |
 * |---|---|
 * | `headline=Die fehlende Ebene` (content create/update) | exit 1, *"Not an allowed value … headline=a:2:{…} (allowed: h1, …, h6)"* |
 * | `width=90 --set width_unit=vw` (layout update) | exit 1, *"Rejected by the DCA rule … width=a:2:{…} (expected: digit)"* |
 *
 * No headline could be set on any content element through the CLI.
 *
 * ## Why
 *
 * `convertFields()` runs every `refuse*` check on the raw input, on purpose.
 * But `ContentCreateCommand` and `AbstractModelUpdateCommand` call
 * `convertInputUnitFields()` **before** it, so the checks received the
 * serialized `{value, unit}` pair — and held all of it against rules that
 * describe only one half:
 *
 * - `options` of an `inputUnit` field lists the **units** (`h1`…`h6`, `px`…),
 * - `eval.rgxp` applies to the **value** (`digit` for a width).
 *
 * That is exactly how Contao's own `InputUnit` widget splits it: the value goes
 * through `Widget::validator()` with the rgxp, the unit comes from the select.
 * The checks now do the same.
 *
 * Regressions: the rgxp half since v0.2.28 (2026-09-01), the options half
 * since v0.9.0 (2026-09-11). No test combined an `inputUnit` field with either
 * check, which is why both releases went out green.
 */
class InputUnitValidationTest extends TestCase
{
    private ImageSizeUpdateCommand $subject;

    protected function setUp(): void
    {
        $this->subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            // tl_content.headline: units are heading levels, the value is free text.
            'headline' => [
                'inputType' => 'inputUnit',
                'options'   => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                'eval'      => ['maxlength' => 200],
            ],
            // tl_layout.width: units are CSS units, the value has to be a number.
            'width' => [
                'inputType' => 'inputUnit',
                'options'   => ['px', '%', 'em', 'rem', 'vw', 'vh'],
                'eval'      => ['rgxp' => 'digit'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    /**
     * The server's own order: convert first, then check.
     *
     * @param array<string, mixed> $fields
     */
    private function convertAndCheck(array $fields, string $defaultUnit): void
    {
        $convert = new \ReflectionMethod($this->subject, 'convertInputUnitFields');
        $fields  = $convert->invoke($this->subject, 'tl_test', $fields, $defaultUnit);

        $this->check($fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function check(array $fields): void
    {
        foreach (['refuseInvalidValues', 'refuseInvalidOptions'] as $name) {
            (new \ReflectionMethod($this->subject, $name))->invoke($this->subject, 'tl_test', $fields);
        }
    }

    // --- what passes: the two measured failures ---

    public function testAHeadlineWithoutUnitPasses(): void
    {
        $this->convertAndCheck(['headline' => 'Die fehlende Ebene'], 'h2');
        $this->addToAssertionCount(1);
    }

    public function testAHeadlineWithAUnitPasses(): void
    {
        $this->convertAndCheck(['headline' => 'Contao, endlich per Befehlszeile', 'headline_unit' => 'h1'], 'h2');
        $this->addToAssertionCount(1);
    }

    public function testANumericWidthWithAUnitPasses(): void
    {
        $this->convertAndCheck(['width' => '90', 'width_unit' => 'vw'], 'px');
        $this->addToAssertionCount(1);
    }

    public function testAnEmptyValueWithAUnitPasses(): void
    {
        // Clearing a width keeps its unit — an empty value is not a format error.
        $this->check(['width' => serialize(['value' => '', 'unit' => 'px'])]);
        $this->addToAssertionCount(1);
    }

    // --- what is still refused: each half against its own rule ---

    public function testTheValueHalfIsStillHeldAgainstTheRgxp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('width=abc');

        $this->convertAndCheck(['width' => 'abc', 'width_unit' => 'vw'], 'px');
    }

    public function testTheUnitHalfIsStillHeldAgainstTheOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('headline unit=h9');

        // Given serialized directly — convertInputUnitFields() would already
        // have replaced an unknown unit.
        $this->check(['headline' => serialize(['value' => 'Titel', 'unit' => 'h9'])]);
    }
}
