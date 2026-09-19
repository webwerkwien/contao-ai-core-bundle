<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Calendar\EventTimes;

/**
 * `EventTimes::adjust()` against what `tl_calendar_events::adjustTime()` stores.
 *
 * The cases are the ones of the practical test on c5 (2026-09-19): an all-day
 * event, one over two days, one with a time — plus the branches of the callback
 * that test did not reach (end before start, end time missing, recurrences).
 */
class EventTimesTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private static function at(string $datetime): int
    {
        return (int) strtotime($datetime);
    }

    public function testAllDayEventEndsAtTheLastSecondOfItsDay(): void
    {
        $set = EventTimes::adjust(['startDate' => self::at('2026-10-04'), 'endDate' => null]);

        $this->assertSame(self::at('2026-10-04 00:00:00'), $set['startTime']);
        $this->assertSame(self::at('2026-10-04 23:59:59'), $set['endTime']);
        $this->assertArrayNotHasKey('endDate', $set, 'a one-day event keeps no end date');
        $this->assertSame(0, $set['repeatEnd']);
    }

    public function testEventOverTwoDaysEndsAtTheEndOfTheLastDay(): void
    {
        $set = EventTimes::adjust(['startDate' => self::at('2026-10-17'), 'endDate' => self::at('2026-10-18')]);

        $this->assertSame(self::at('2026-10-17 00:00:00'), $set['startTime']);
        $this->assertSame(self::at('2026-10-18 23:59:59'), $set['endTime']);
        $this->assertSame(self::at('2026-10-18'), $set['endDate']);
    }

    public function testTheBugOfTheOldCreate(): void
    {
        // What event create stored until v0.28.0: endDate = the day of the call.
        // Contao moves an end before the start onto the start.
        $set = EventTimes::adjust(['startDate' => self::at('2026-10-04'), 'endDate' => self::at('2026-09-19')]);

        $this->assertSame(self::at('2026-10-04'), $set['endDate']);
        $this->assertSame(self::at('2026-10-04 23:59:59'), $set['endTime']);
    }

    public function testEventWithTimeTakesTheTimeOfDayOnItsDate(): void
    {
        $set = EventTimes::adjust([
            'startDate' => self::at('2026-10-09'),
            'addTime'   => '1',
            'startTime' => EventTimes::timeOfDay('17:30'),
            'endTime'   => EventTimes::timeOfDay('20:00'),
        ]);

        $this->assertSame(self::at('2026-10-09 17:30'), $set['startTime']);
        $this->assertSame(self::at('2026-10-09 20:00'), $set['endTime']);
    }

    public function testAFullTimestampContributesOnlyItsTimeOfDay(): void
    {
        // The back end reads date('H:i:s', startTime) — the date part comes from startDate.
        $set = EventTimes::adjust([
            'startDate' => self::at('2026-10-09'),
            'addTime'   => true,
            'startTime' => self::at('2025-01-01 08:15'),
        ]);

        $this->assertSame(self::at('2026-10-09 08:15'), $set['startTime']);
        $this->assertSame(self::at('2026-10-09 08:15'), $set['endTime'], 'no end time: ends when it starts');
    }

    public function testTimedEventOverSeveralDays(): void
    {
        $set = EventTimes::adjust([
            'startDate' => self::at('2026-10-17'),
            'endDate'   => self::at('2026-10-18'),
            'addTime'   => 1,
            'startTime' => EventTimes::timeOfDay('09:00'),
            'endTime'   => EventTimes::timeOfDay('16:00'),
        ]);

        $this->assertSame(self::at('2026-10-17 09:00'), $set['startTime']);
        $this->assertSame(self::at('2026-10-18 16:00'), $set['endTime']);
    }

    public function testAddTimeOffIgnoresStoredTimes(): void
    {
        $set = EventTimes::adjust([
            'startDate' => self::at('2026-10-09'),
            'addTime'   => '0',
            'startTime' => self::at('2026-10-09 17:30'),
            'endTime'   => self::at('2026-10-09 20:00'),
        ]);

        $this->assertSame(self::at('2026-10-09 00:00'), $set['startTime']);
        $this->assertSame(self::at('2026-10-09 23:59:59'), $set['endTime']);
    }

    public function testNoStartDateChangesNothing(): void
    {
        $this->assertSame([], EventTimes::adjust(['startDate' => null, 'endDate' => self::at('2026-10-09')]));
        $this->assertSame([], EventTimes::adjust([]));
    }

    public function testUnlimitedRecurrence(): void
    {
        $set = EventTimes::adjust(['startDate' => self::at('2026-10-04'), 'recurring' => '1', 'recurrences' => 0]);

        $this->assertSame(min(4294967295, PHP_INT_MAX), $set['repeatEnd']);
    }

    public function testLimitedRecurrenceFromStoredAndFromJson(): void
    {
        $stored = EventTimes::adjust([
            'startDate'   => self::at('2026-10-04'),
            'recurring'   => '1',
            'recurrences' => 3,
            'repeatEach'  => serialize(['unit' => 'weeks', 'value' => 2]),
        ]);
        $json = EventTimes::adjust([
            'startDate'   => self::at('2026-10-04'),
            'recurring'   => '1',
            'recurrences' => '3',
            'repeatEach'  => '{"unit":"weeks","value":2}',
        ]);

        $expected = (int) strtotime('+ 6 weeks', self::at('2026-10-04 23:59:59'));
        $this->assertSame($expected, $stored['repeatEnd']);
        $this->assertSame($expected, $json['repeatEnd']);
    }

    public function testRefusalOfAnEmptiedStartDate(): void
    {
        $this->assertStringContainsString('start date', (string) EventTimes::refusal(['startDate' => ''], ['startDate' => 1]));
        $this->assertStringContainsString('start date', (string) EventTimes::refusal(['startDate' => null]));
    }

    public function testRefusalOfTimesOnAnAllDayEvent(): void
    {
        $allDay = ['startDate' => self::at('2026-10-09'), 'addTime' => false];

        $message = EventTimes::refusal(['startTime' => (string) self::at('2026-10-09 17:30')], $allDay);
        $this->assertStringContainsString('startTime apply only to an event with a time', (string) $message);
        $this->assertNull(EventTimes::refusal(['addTime' => '1', 'startTime' => EventTimes::timeOfDay('17:30')], $allDay));
    }

    public function testRefusalOfAnEndTimeThatWouldStartTheEventAtMidnight(): void
    {
        $allDay = ['startDate' => self::at('2026-10-09'), 'addTime' => '0'];

        // What `event update --endTime 21:00` sends: the option sets addTime.
        $message = EventTimes::refusal(['addTime' => '1', 'endTime' => EventTimes::timeOfDay('21:00')], $allDay);
        $this->assertStringContainsString('--startTime', (string) $message);
        $this->assertStringContainsString('--startTime', (string) EventTimes::refusal(['addTime' => '1'], $allDay));
    }

    public function testTimedEventsMayChangeOneTimeAlone(): void
    {
        $timed = ['startDate' => self::at('2026-10-09'), 'addTime' => '1', 'startTime' => self::at('2026-10-09 17:30')];

        $this->assertNull(EventTimes::refusal(['addTime' => '1', 'endTime' => EventTimes::timeOfDay('21:00')], $timed));
        $this->assertNull(EventTimes::refusal(['addTime' => '0'], $timed), 'back to all-day');
        $this->assertNull(EventTimes::refusal(['startDate' => self::at('2026-10-10')], $timed));
    }

    public function testCreateWithoutTimesIsFine(): void
    {
        $this->assertNull(EventTimes::refusal(['startDate' => self::at('2026-10-04'), 'endDate' => null, 'published' => '1']));
    }

    public function testDayAndTimeOfDayParsing(): void
    {
        $this->assertSame(self::at('2026-10-04 00:00'), EventTimes::day('2026-10-04'));
        $this->assertSame((int) strtotime('1970-01-01 17:30'), EventTimes::timeOfDay('17:30'));
        $this->assertSame((int) strtotime('1970-01-01 07:05'), EventTimes::timeOfDay('7:05'));
    }

    /**
     * @dataProvider badDays
     */
    public function testBadDaysAreRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');
        EventTimes::day($value);
    }

    public static function badDays(): iterable
    {
        yield 'German order' => ['04.10.2026'];
        yield 'impossible day' => ['2026-02-30'];
        yield 'with time' => ['2026-10-04 17:30'];
        yield 'words' => ['tomorrow'];
    }

    /**
     * @dataProvider badTimes
     */
    public function testBadTimesAreRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HH:MM');
        EventTimes::timeOfDay($value);
    }

    public static function badTimes(): iterable
    {
        yield 'hour 24' => ['24:00'];
        yield 'seconds' => ['17:30:00'];
        yield 'am/pm' => ['5:30pm'];
        yield 'dot' => ['17.30'];
    }
}
