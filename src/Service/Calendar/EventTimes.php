<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Calendar;

use Contao\StringUtil;

/**
 * The stored start and end of a calendar event, as Contao derives them.
 *
 * An event carries its dates twice: `startDate`/`endDate` hold the days the
 * editor picked, `startTime`/`endTime` the moments the front end works with —
 * every event list asks `endTime >= now`. The back end derives the second pair
 * from the first in `tl_calendar_events::adjustTime()`, an `onsubmit_callback`
 * that this write path never runs.
 *
 * Found in the practical test of 2026-09-19 on c5: `event create` stored
 * `endTime = endDate`, and `endDate` defaulted to the day of the call. A single
 * day event on 4 October ended on 19 September at midnight and was missing from
 * "upcoming events" the moment it was created; a two-day event would have left
 * the list at midnight of its last day instead of at its end.
 *
 * Mirrors `adjustTime()` of Contao 5.3, 5.7 and 6.0 line for line, including the
 * recurrence end. Returns the columns that callback writes; the caller merges
 * them into its write.
 */
final class EventTimes
{
    /** The fields whose change makes the back end recompute the times. */
    public const INPUTS = ['startDate', 'endDate', 'addTime', 'startTime', 'endTime', 'recurring', 'recurrences', 'repeatEach'];

    /**
     * @param array<string, mixed> $row the event as it will be stored (stored row with the change merged in)
     *
     * @return array<string, int|null> startTime, endTime, repeatEnd and, where Contao sets it, endDate —
     *                                 empty when there is no start date yet (Contao returns early too)
     */
    public static function adjust(array $row): array
    {
        $startDate = self::int($row['startDate'] ?? null);

        if (!$startDate) {
            return [];
        }

        $endDate = self::int($row['endDate'] ?? null);
        $set     = ['startTime' => $startDate, 'endTime' => $startDate];

        if ($endDate) {
            if ($endDate > $startDate) {
                $set['endDate'] = $endDate;
                $set['endTime'] = $endDate;
            } else {
                $set['endDate'] = $startDate;
                $set['endTime'] = $startDate;
            }
        }

        if (self::flag($row['addTime'] ?? null)) {
            $startTime = self::int($row['startTime'] ?? null) ?? 0;
            $endTime   = self::int($row['endTime'] ?? null);

            $set['startTime'] = strtotime(date('Y-m-d', $set['startTime']) . ' ' . date('H:i:s', $startTime));
            $set['endTime']   = strtotime(date('Y-m-d', $set['endTime']) . ' ' . date('H:i:s', $endTime ?? $startTime));
        } elseif (($endDate && $set['endDate'] === $set['endTime']) || $set['startTime'] === $set['endTime']) {
            // An all day event ends at the last second of its last day.
            $set['endTime'] = strtotime('+ 1 day', $set['endTime']) - 1;
        }

        $set['repeatEnd'] = 0;

        if (self::flag($row['recurring'] ?? null)) {
            $recurrences = (int) ($row['recurrences'] ?? 0);

            if (0 === $recurrences) {
                // Unlimited recurrences end on 2106-02-07 07:28:15 (Contao #4862, #510).
                $set['repeatEnd'] = min(4294967295, PHP_INT_MAX);
            } else {
                $range = self::range($row['repeatEach'] ?? null);

                if (isset($range['unit'], $range['value'])) {
                    $set['repeatEnd'] = strtotime('+ ' . ((int) $range['value'] * $recurrences) . ' ' . $range['unit'], $set['endTime']);
                }
            }
        }

        return $set;
    }

    /**
     * Why a change would leave the event in a state the back end does not allow, or null.
     *
     * The back end cannot get there: `startDate` is mandatory, and the time fields
     * only appear (and `startTime` is mandatory) once `addTime` is ticked. Here each
     * would answer `ok` and store something else than asked (review before v0.28.0):
     *
     *  - `--set startDate=` stored NULL, the event was listed nowhere;
     *  - `--set startTime=<ts>` without `addTime` was reset to midnight by `adjust()`;
     *  - `--endTime 21:00` on an all-day event started it at 00:00.
     *
     * @param array<string, mixed> $changed the fields this write sets
     * @param array<string, mixed> $stored  the event as stored ([] on create)
     */
    public static function refusal(array $changed, array $stored = []): ?string
    {
        if (\array_key_exists('startDate', $changed) && null === self::int($changed['startDate'])) {
            return 'An event needs a start date; startDate cannot be emptied.';
        }

        $timed     = self::flag(\array_key_exists('addTime', $changed) ? $changed['addTime'] : ($stored['addTime'] ?? null));
        $wasTimed  = self::flag($stored['addTime'] ?? null);
        $setsTimes = array_filter(['startTime', 'endTime'], static fn (string $f): bool => null !== self::int($changed[$f] ?? null));

        if (!$timed && [] !== $setsTimes) {
            return \sprintf(
                '%s apply only to an event with a time, and this one lasts all day. Pass --startTime (it sets addTime) or --set addTime=1 with it.',
                implode(' and ', $setsTimes),
            );
        }

        if ($timed && !$wasTimed && !\in_array('startTime', $setsTimes, true)) {
            return 'An event gets a time with its start time: pass --startTime (HH:MM) as well.';
        }

        return null;
    }

    /**
     * A time of day as the back end's `rgxp=time` widget stores it: that time on
     * 1 January 1970, in the server's time zone. Only the time part is read later.
     *
     * @throws \InvalidArgumentException when the value is not HH:MM
     */
    public static function timeOfDay(string $value): int
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value))) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a time of day. Use HH:MM, e.g. 17:30.', $value));
        }

        return (int) strtotime('1970-01-01 ' . trim($value));
    }

    /**
     * Midnight of a day given as YYYY-MM-DD, in the server's time zone.
     *
     * @throws \InvalidArgumentException when the value is not a real date in that form
     */
    public static function day(string $value): int
    {
        $value = trim($value);
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a date. Use YYYY-MM-DD, e.g. 2026-10-04.', $value));
        }

        return $date->getTimestamp();
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function flag(mixed $value): bool
    {
        return \is_string($value) ? !\in_array(strtolower(trim($value)), ['', '0', 'false'], true) : (bool) $value;
    }

    /**
     * `repeatEach` as stored (serialized), as typed on the command line (JSON) or already an array.
     *
     * @return array<string, mixed>
     */
    private static function range(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (\is_string($value) && str_starts_with(ltrim($value), '{')) {
            $decoded = json_decode($value, true);

            return \is_array($decoded) ? $decoded : [];
        }

        $value = StringUtil::deserialize($value, true);

        return \is_array($value) ? $value : [];
    }
}
