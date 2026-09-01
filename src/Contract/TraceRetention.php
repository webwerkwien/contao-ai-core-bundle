<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Contract;

use Contao\Config;

/**
 * How long a trail survives — read from this installation, not declared.
 *
 * A command declaring "writes a version" and one declaring "writes a log
 * entry" sound interchangeable and are an order of magnitude apart:
 *
 *     tl_log       logPeriod       7 days
 *     tl_undo      undoPeriod     30 days
 *     tl_version   versionPeriod  90 days
 *
 * `PurgeExpiredDataCron` runs hourly. Whoever relies on a trail relies on its
 * period too, and neither wording carried it — the omission was found by the
 * ww-buchung session on 2026-09-01 and is the reason this class exists.
 *
 * 🎯 **It is read, never declared.** The value belongs to the installation, not
 * to the command, and a command that stated its own period would be stating
 * something it does not control. Contao 5.7 keeps no `tl_settings` table; the
 * values live in `$GLOBALS['TL_CONFIG']` from `default.php`, overridable in
 * `localconfig.php`, and `Config::get()` is what reads them.
 */
final class TraceRetention
{
    /** trail table => the Contao setting that expires it */
    private const SETTINGS = [
        'tl_log'     => 'logPeriod',
        'tl_undo'    => 'undoPeriod',
        'tl_version' => 'versionPeriod',
    ];

    /**
     * @param list<string> $traces
     *
     * @return array<string, array{setting: string, seconds: int|null, days: float|null}>
     */
    public static function forTraces(array $traces): array
    {
        $out = [];

        foreach ($traces as $trace) {
            if (!isset(self::SETTINGS[$trace])) {
                continue;
            }

            $setting = self::SETTINGS[$trace];
            $seconds = self::seconds($setting);

            $out[$trace] = [
                'setting' => $setting,
                'seconds' => $seconds,
                // 0 means "never expire" in Contao, and that is a different
                // statement from "unknown" — kept apart rather than collapsed
                // into one null.
                'days'    => null === $seconds ? null : round($seconds / 86400, 1),
            ];
        }

        return $out;
    }

    private static function seconds(string $setting): ?int
    {
        if (!class_exists(Config::class)) {
            return null;
        }

        $value = Config::get($setting);

        return is_numeric($value) ? (int) $value : null;
    }
}
