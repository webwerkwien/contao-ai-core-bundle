<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

/**
 * Refuse writes to the two fields that fake a newsletter send.
 *
 * This bundle deliberately offers no send command (decision of 2026-08-31,
 * recorded in the project file). Contao's send routine is browser-driven — a
 * JS timer advances the cycles — and a mail that has gone out cannot be taken
 * back, so the send stays with a person in the back end.
 *
 * 🎯 **A refusal that only lives in the CLI is not a boundary, it is a detour
 * sign.** `sent` and `date` have no `inputType` and appear in no palette, so
 * the back end cannot write them either — but `--set` reaches any column, and
 * an agent that finds `newsletter send` missing has an obvious next idea.
 * `UPDATE tl_newsletter SET sent=1` is the worst outcome available here: it
 * sends nothing and publishes the newsletter in the front end, because
 * `NewsletterModel::findSentByPid()` filters on exactly that flag. The record
 * then reads "sent" in the back end while no recipient ever got it.
 *
 * So the guard sits on the write path itself, where every route passes —
 * create, update, and the bulk `--ids` form alike.
 *
 * Not covered, and knowingly so: raw SQL through `doctrine:query:sql`. Nothing
 * in this bundle can stop that, which is why the same refusal is spelled out in
 * the CLI's README and CLAUDE.md — the point is that an agent reads the reason
 * before it goes looking for a way around.
 */
final class NewsletterSendGuard
{
    /**
     * Fields that only Contao's send routine may write.
     *
     * `sent` is the flag; `date` is the send timestamp beside it. Writing
     * either one alone produces a record no back end action could have made.
     */
    private const FORBIDDEN = ['sent', 'date'];

    /**
     * @param array<string, mixed> $fields the parsed `--set` pairs
     *
     * @return string|null the refusal message, or null when nothing is amiss
     */
    public static function refuse(array $fields): ?string
    {
        $hit = array_values(array_intersect(self::FORBIDDEN, array_keys($fields)));

        if ([] === $hit) {
            return null;
        }

        return \sprintf(
            'Refusing to write tl_newsletter.%s — by design, not a missing feature. '
            . '`sent` and `date` are written only by Contao\'s send routine, and writing '
            . 'either one fakes the result without producing it: a newsletter marked sent '
            . 'and published in the front end that no recipient ever got. That is worse '
            . 'than doing nothing. Sending stays with a person in the Contao back end: '
            . 'Newsletters -> channel -> send icon.',
            implode(', tl_newsletter.', $hit),
        );
    }
}
