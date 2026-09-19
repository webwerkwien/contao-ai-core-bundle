<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A `timePeriod` field holds `{value, unit}`; its options list the units.
 *
 * Found on Contao 5.3.51 on 2026-09-19: `event create --set recurring=1
 * --set repeatEach={"unit":"weeks","value":1}` was refused with "Not an allowed
 * value … repeatEach=a:2:{…} (allowed: days, weeks, months, years)" — the whole
 * serialized pair was held against the unit list, so no recurring event could be
 * created in any form. Same fix as for `inputUnit`: only the unit half goes
 * against the options, the value half belongs to `rgxp` (see RgxpPartsTest).
 */
class TimePeriodOptionTest extends TestCase
{
    private function refusalFor(array $fields): ?string
    {
        $subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        try {
            (new \ReflectionMethod($subject, 'refuseInvalidOptions'))->invoke($subject, 'tl_test', $fields);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function setUp(): void
    {
        // As in tl_calendar_events (5.3, 5.7 and 6.0).
        $GLOBALS['TL_DCA']['tl_test']['fields']['repeatEach'] = [
            'inputType' => 'timePeriod',
            'options'   => ['days', 'weeks', 'months', 'years'],
            'eval'      => ['rgxp' => 'natural', 'minval' => 1],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    public function testAPairWithAKnownUnitPasses(): void
    {
        $this->assertNull($this->refusalFor(['repeatEach' => serialize(['unit' => 'weeks', 'value' => '1'])]));
        $this->assertNull($this->refusalFor(['repeatEach' => ['unit' => 'days', 'value' => 3]]));
    }

    public function testAnUnknownUnitIsRefusedByName(): void
    {
        $message = $this->refusalFor(['repeatEach' => serialize(['unit' => 'fortnights', 'value' => '1'])]);

        $this->assertNotNull($message);
        $this->assertStringContainsString('repeatEach unit=fortnights', $message);
        $this->assertStringContainsString('days, weeks, months, years', $message);
    }

    public function testAnEmptyValueIsNotAChoice(): void
    {
        $this->assertNull($this->refusalFor(['repeatEach' => '']));
    }
}
