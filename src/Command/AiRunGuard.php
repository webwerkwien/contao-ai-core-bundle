<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

/**
 * Which command names `contao:ai:run` will touch at all.
 *
 * The rule is one line — the name must start with `contao:` — and the reason it
 * is a class rather than a condition is that getting it wrong is expensive.
 *
 * 🎯 **Without the restriction, `ai:run` is a remote console, and the first
 * thing it hands back is `doctrine:query:sql`.** This bundle spent the whole of
 * 2026-08-31 and 2026-09-01 moving reads and writes off raw SQL and onto
 * commands that validate against the DCA, version, and log. A generic runner
 * that reaches `doctrine:query:sql` puts every one of those guarantees back on
 * the honour system, through the very tool that was built to end that.
 *
 * The same applies to `cache:clear`, `messenger:consume`, `debug:container` and
 * the rest of the framework's namespace: not dangerous by nature, but not what
 * this is for, and each one is reachable through its own wrapper or over SSH by
 * someone who has decided to.
 *
 * ⚠️ **This is not a security boundary and must not be described as one.**
 * Whoever calls the CLI has shell access to the site — they can run anything.
 * It is a boundary on what *this tool* will do on its own, which is a different
 * and smaller claim: it keeps an agent from wandering out of the audited path
 * by accident.
 */
final class AiRunGuard
{
    public static function isAllowed(string $command): bool
    {
        return str_starts_with($command, 'contao:');
    }

    public static function refusal(string $command): string
    {
        return \sprintf(
            'Refusing "%s": contao:ai:run only reaches commands under `contao:`. '
            . 'The framework namespace is deliberately out of reach — doctrine:query:sql '
            . 'through here would put every DCA rule, version and log entry this bundle '
            . 'writes back on the honour system. Use the dedicated command, or a shell.',
            $command,
        );
    }
}
