<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * `eval.rgxp` is held against the parts Contao's widget holds it against — never
 * against a serialized whole or a comma list.
 *
 * 🔴 **Measured on 2026-09-16 on c5 (Contao 5.7.13, core-bundle v0.13.0)** in the ConpAI
 * 1.0 acceptance test:
 *
 * | `--set` | answer |
 * |---|---|
 * | `size=a:3:{i:0;s:0:"";i:1;s:0:"";i:2;s:1:"6";}` (image element) | *expected: natural* — and a bare `6` is refused as unstructured since v0.12.0, so **no image size could be set at all** |
 * | `playerSize=640,360` | *expected: natural* |
 * | `playerSize=a:2:{…}` | *expected: natural* |
 *
 * v0.11.0 fixed this for `inputUnit` only. The same rule — the widget decides which
 * part the rgxp applies to — holds for the others, read from Contao 5.7.13:
 *
 * | widget | rgxp applies to | the rest |
 * |---|---|---|
 * | `inputUnit` | `value` | `unit` against options |
 * | `imageSize` (`ImageSize::validator()`) | `[0]` width, `[1]` height | `[2]` against options (a size ID or mode) |
 * | `timePeriod` (`TimePeriod::validator()`) | `value` | `unit` against options |
 * | `text` with `eval.multiple` | every entry | — |
 *
 * Fields with a multi-entry rgxp in stock 5.7.13: `tl_content.playerSize`,
 * `tl_content.mooClasses`, `tl_module.contextLength` and `tl_form_field.size` — the last
 * one mandatory, so those form fields could not be created.
 */
class RgxpPartsTest extends TestCase
{
    private ImageSizeUpdateCommand $subject;

    protected function setUp(): void
    {
        $this->subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'size'       => ['inputType' => 'imageSize', 'eval' => ['rgxp' => 'natural']],
            'period'     => ['inputType' => 'timePeriod', 'eval' => ['rgxp' => 'natural'], 'options' => ['days', 'weeks']],
            'playerSize' => ['inputType' => 'text', 'eval' => ['multiple' => true, 'size' => 2, 'rgxp' => 'natural']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function check(array $fields): void
    {
        (new \ReflectionMethod($this->subject, 'refuseInvalidValues'))->invoke($this->subject, 'tl_test', $fields);
    }

    /**
     * @dataProvider accepted
     */
    public function testAValidValuePasses(string $field, string $value): void
    {
        $this->check([$field => $value]);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accepted(): array
    {
        return [
            'image size id only (the measured case)' => ['size', serialize(['', '', '6'])],
            'image size width and height'            => ['size', serialize(['640', '360', 'proportional'])],
            'image size with only a width'           => ['size', serialize(['640', '', 'box'])],
            'time period'                            => ['period', serialize(['value' => '5', 'unit' => 'days'])],
            'multiple text as a comma list'          => ['playerSize', '640,360'],
            'multiple text serialized'               => ['playerSize', serialize(['640', '360'])],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testAnInvalidPartIsStillRefusedAndNamed(string $field, string $value, string $named): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($named);

        $this->check([$field => $value]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refused(): array
    {
        return [
            'image size width not a number'  => ['size', serialize(['breit', '360', '6']), 'size=breit'],
            'time period value not a number' => ['period', serialize(['value' => 'fünf', 'unit' => 'days']), 'period=fünf'],
            'one entry of a comma list'      => ['playerSize', '640,hoch', 'playerSize=hoch'],
            'one entry serialized'           => ['playerSize', serialize(['640', 'hoch']), 'playerSize=hoch'],
        ];
    }
}
