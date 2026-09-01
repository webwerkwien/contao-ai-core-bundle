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
 * ## Why the namespace alone was the wrong measure (2026-09-01)
 *
 * A parallel session measured 22 namespaces on a live site. `cookiebar:` is a
 * published Contao extension using its own product name; Symfony's own docs
 * recommend `app:` for application commands. **A prefix of one's own is the
 * convention, and `contao:` would be the one wrong choice** — it claims someone
 * else's property.
 *
 * 🎯 Their diagnosis names the fault exactly: *the prefix says who wrote the
 * command; this guard read it as whether the command may be run.* Two different
 * questions of one source. A correctly named extension command fell out — not
 * because it was dangerous, but because the name does not carry the information
 * being demanded of it.
 *
 * So a declared `#[AiContract]` opens the door as well. That permission comes
 * from the **author**, which is the answer the prefix could never give. The
 * default stays closed, nothing is granted that shell access did not already
 * grant, and `ai:run` still warns and logs. The alternative — a deny-list of
 * framework namespaces — is the "everyone must remember" shape this project has
 * already failed at twice.
 *
 * ⚠️ **This is not a security boundary and must not be described as one.**
 * Whoever calls the CLI has shell access to the site — they can run anything.
 * It is a boundary on what *this tool* will do on its own, which is a different
 * and smaller claim: it keeps an agent from wandering out of the audited path
 * by accident.
 */
final class AiRunGuard
{
    /**
     * @param bool $declaresContract whether the command carries an #[AiContract]
     */
    public static function isAllowed(string $command, bool $declaresContract = false): bool
    {
        return $declaresContract || str_starts_with($command, 'contao:');
    }

    public static function refusal(string $command): string
    {
        return \sprintf(
            'Refusing "%s": contao:ai:run reaches commands under `contao:`, and commands that '
            . 'declare an #[AiContract]. This one does neither. The framework namespace is '
            . 'deliberately out of reach — doctrine:query:sql through here would put every DCA '
            . 'rule, version and log entry this bundle writes back on the honour system. '
            . 'If this is your own command, declaring a contract makes it reachable and says '
            . 'what it does; see the bundle README. Otherwise use the dedicated command, or a shell.',
            $command,
        );
    }
}
