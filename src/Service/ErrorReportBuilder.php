<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Composer\InstalledVersions;

/**
 * Turns a `Throwable` into an {@see ErrorReport} someone can actually act on.
 *
 * ## Why this exists
 *
 * Failures were already collected — `ToolCallLogger` writes every tool call and
 * its outcome, the write commands log an audit line. What was missing is getting
 * them *out*: everything lands in `var/logs/prod-*.log`, reachable only by
 * someone with SSH. A user whose back end says "Serverfehler" can hand the
 * maintainer nothing, and `tl_log` does not help — `ContaoTableHandler` formats
 * with `LineFormatter('%message%')`, so the context is dropped there.
 *
 * ## Allow-list, not masking
 *
 * The summary is assembled from named fields rather than filtered after the
 * fact. Masking asks "does this look like a secret?", which is a guess that goes
 * stale; an allow-list asks "is this on the list?", which cannot. The masking in
 * {@see CredentialMasker} still runs over the message, but as the second line of
 * defence, not the first.
 *
 * ## What deliberately does not reach a report
 *
 * Argument *values*, user text, prompts, page content, absolute paths outside
 * our own packages. Argument **keys** do — they say which call failed without
 * saying what was in it.
 */
final class ErrorReportBuilder
{
    /**
     * Packages whose versions belong in every report.
     *
     * The CLI is absent on purpose: it is not a Composer package (installed via
     * pipx from git), so it cannot be looked up here and passes its own version
     * through `$context['versions']` instead.
     *
     * @var array<string, string>
     */
    private const PACKAGES = [
        'core'    => 'webwerkwien/contao-ai-core-bundle',
        'backend' => 'webwerkwien/contao-ai-backend-bundle',
        'contao'  => 'contao/core-bundle',
        'symfony' => 'symfony/http-kernel',
    ];

    /** Beyond this the trace stops being read and starts being scrolled past. */
    private const MAX_FRAMES = 25;

    /**
     * @param string               $component 'core', 'backend' or 'cli'
     * @param array<string, mixed> $context   optional: `tool`, `call_id`, `platform`,
     *                                        `argument_keys` (list<string>), `versions`
     *                                        (array<string,string> merged over the
     *                                        detected ones)
     * @param list<string>         $knownSecrets literal values held by the caller —
     *                                        the user's API key, a bridge token
     */
    public function build(
        \Throwable $e,
        string $component,
        array $context = [],
        #[\SensitiveParameter] array $knownSecrets = [],
    ): ErrorReport {
        $summary = [
            'zeitpunkt'  => gmdate('Y-m-d H:i') . ' UTC',
            'komponente' => $component,
            'versionen'  => $this->versions($context['versions'] ?? []),
            'umgebung'   => [
                'php' => \PHP_VERSION,
                'os'  => \PHP_OS_FAMILY,
            ],
            'ausnahme' => [
                'klasse' => $e::class,
                'datei'  => $this->shortenPath($e->getFile()),
                'zeile'  => $e->getLine(),
            ],
        ];

        foreach (['tool' => 'werkzeug', 'call_id' => 'aufruf_id', 'platform' => 'plattform'] as $key => $label) {
            if (isset($context[$key]) && \is_scalar($context[$key])) {
                $summary[$label] = (string) $context[$key];
            }
        }

        if (isset($context['argument_keys']) && \is_array($context['argument_keys'])) {
            // Values are never taken, only the keys — and those are cast so a
            // caller handing over the whole argument array by mistake still
            // cannot leak one. `array_keys()` on a list yields 0,1,2…, which is
            // useless but harmless; on a map it yields exactly what we want.
            $summary['argument_schluessel'] = array_map(
                static fn ($k): string => (string) $k,
                array_keys($context['argument_keys']) === range(0, \count($context['argument_keys']) - 1)
                    ? array_values($context['argument_keys'])
                    : array_keys($context['argument_keys']),
            );
        }

        return new ErrorReport(
            $summary,
            CredentialMasker::mask($e->getMessage(), ...$knownSecrets),
            $this->trace($e, $knownSecrets),
        );
    }

    /**
     * @param  array<string, string> $extra
     * @return array<string, string>
     */
    private function versions(array $extra): array
    {
        $versions = [];

        foreach (self::PACKAGES as $label => $package) {
            $versions[$label] = $this->packageVersion($package);
        }

        foreach ($extra as $label => $version) {
            $versions[(string) $label] = (string) $version;
        }

        return array_filter($versions, static fn (string $v): bool => '' !== $v);
    }

    /**
     * `InstalledVersions` throws for a package that is not installed rather than
     * returning null, and the backend bundle legitimately is not installed on a
     * CLI-only setup. An absent package is not an error worth propagating out of
     * an error reporter.
     */
    private function packageVersion(string $package): string
    {
        try {
            return InstalledVersions::getPrettyVersion($package) ?? '';
        } catch (\OutOfBoundsException) {
            return '';
        }
    }

    /**
     * Shortens a path so it locates the code without describing the server.
     *
     * An absolute path on a shared host reads like `/var/www/clients/client1/
     * web246/web/vendor/…` — the leading part is the customer's hosting layout
     * and has no diagnostic value. Everything from `vendor/` on is kept because
     * that is the part that identifies the package; a file outside any vendor
     * directory keeps its basename only.
     */
    private function shortenPath(string $file): string
    {
        $normalised = str_replace('\\', '/', $file);
        $position   = strrpos($normalised, '/vendor/');

        if (false !== $position) {
            return substr($normalised, $position + 1);
        }

        return basename($normalised);
    }

    /**
     * Own frames in full, foreign frames collapsed.
     *
     * A stack trace is mostly framework plumbing. Keeping our own frames and
     * summarising runs of foreign ones keeps the report readable and, as a side
     * effect, drops the argument values PHP would otherwise render into
     * `getTraceAsString()`.
     *
     * @param  list<string> $knownSecrets
     * @return list<string>
     */
    private function trace(\Throwable $e, array $knownSecrets): array
    {
        $frames  = [];
        $skipped = 0;

        foreach (\array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            if (!$this->isOwnFrame($frame)) {
                ++$skipped;
                continue;
            }

            if ($skipped > 0) {
                $frames[] = \sprintf('… %d Aufruf(e) außerhalb von contao-ai', $skipped);
                $skipped  = 0;
            }

            $frames[] = CredentialMasker::mask(\sprintf(
                '%s%s%s() in %s:%s',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '?',
                isset($frame['file']) ? $this->shortenPath((string) $frame['file']) : '?',
                $frame['line'] ?? '?',
            ), ...$knownSecrets);
        }

        if ($skipped > 0) {
            $frames[] = \sprintf('… %d Aufruf(e) außerhalb von contao-ai', $skipped);
        }

        return $frames;
    }

    /**
     * The class name decides where there is one; the path only fills the gap.
     *
     * 🔴 The first version asked whether the path contained `contao-ai-` and
     * treated that as ours. In a development checkout the whole project *is* a
     * directory called `contao-ai-core-bundle`, and `vendor/` sits inside it —
     * so every framework frame matched. The first test run put ten PHPUnit
     * frames into the report as our own code.
     *
     * The fix is to look only at the part **after the last `/vendor/`**: in an
     * installation that is `webwerkwien/contao-ai-core-bundle/src/…` and matches,
     * for a foreign package it is `phpunit/phpunit/src/…` and does not, and in a
     * checkout without any vendor segment the whole path is considered — which
     * is correct there.
     *
     * @param array<string, mixed> $frame
     */
    private function isOwnFrame(array $frame): bool
    {
        // A frame with a class is fully answered by that class: a foreign class
        // in one of our files (a Contao callback, say) is not our frame either.
        if (isset($frame['class'])) {
            return str_starts_with((string) $frame['class'], 'Webwerkwien\\');
        }

        if (!isset($frame['file'])) {
            return false;
        }

        $path        = str_replace('\\', '/', (string) $frame['file']);
        $vendorAt    = strrpos($path, '/vendor/');
        $afterVendor = false !== $vendorAt ? substr($path, $vendorAt + \strlen('/vendor/')) : $path;

        return str_contains($afterVendor, 'contao-ai-');
    }
}
