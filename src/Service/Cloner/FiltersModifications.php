<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

/**
 * Shared handling of the `--modifications` whitelist.
 *
 * **Why the ignored keys are reported.** Until 2026-08-29 each cloner filtered
 * the caller's overrides against its own allow-list and dropped the rest in
 * silence — no error, no warning, nothing in the response. A clone call with
 * `{"published":"","hide":"1"}` therefore produced two pages that inherited
 * `published = 1` from their source and were publicly reachable for about
 * three minutes, while the command reported `status: ok`. The caller has no
 * way to tell an applied override from a discarded one, so the result now
 * carries `ignored_modifications`.
 *
 * The allow-list itself stays: it keeps a clone from being turned into an
 * arbitrary record through a field the cascade logic does not expect.
 */
trait FiltersModifications
{
    /**
     * Split the caller's overrides into the ones this cloner accepts and the
     * names of the ones it refuses.
     *
     * @param array<string, scalar|null> $modifications
     * @param list<string>               $allowed
     *
     * @return array{accepted: array<string, scalar|null>, ignored: list<string>}
     */
    protected function partitionModifications(array $modifications, array $allowed): array
    {
        $accepted = [];
        $ignored  = [];

        foreach ($modifications as $key => $value) {
            if (\in_array($key, $allowed, true)) {
                $accepted[$key] = $value;
            } else {
                $ignored[] = (string) $key;
            }
        }

        return ['accepted' => $accepted, 'ignored' => $ignored];
    }

    /**
     * Normalise a caller-supplied boolean-ish override to what a Contao tinyint
     * flag column expects.
     *
     * Callers write `""` for "off" — that is how the 2026-08-29 call was
     * phrased. Passing it through would put an empty string into a tinyint
     * column, which is exactly the write that had to be fixed in v0.2.10 for
     * `unpublish`: it throws on a server with strict SQL mode.
     */
    protected function normaliseFlag(mixed $value): string
    {
        if (null === $value || '' === $value || '0' === $value || 0 === $value || false === $value) {
            return '0';
        }

        return '1';
    }
}
