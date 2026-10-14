<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

/**
 * Clones a tl_newsletter_channel record with its newsletters, and its recipients only
 * on request.
 *
 * The newsletters come out unsent: `sent` is `doNotCopy`, and so is the alias, which
 * is generated anew from the subject with a uniqueness suffix (`eval.unique`).
 *
 * 🎯 **Recipients only with `recipients` in the options** (`--with-recipients`),
 * decided by Michael on 2026-10-14. Contao's own copy takes them along. But a
 * recipient consented to the source channel, not to its copy, and after a clone into
 * another language they would receive a channel they never subscribed to. Whoever
 * passes the option carries that consent, as with Contao's CSV import. Copied
 * recipients keep `active` and lose `addedOn` (`doNotCopy`) — Contao's list then shows
 * them as added manually, which is what they are.
 *
 * Recipients get no versions: neither Contao's copy nor its CSV import writes any,
 * and a list of a few thousand addresses would otherwise add as many rows to
 * `tl_version`.
 */
class NewsletterChannelCloner extends AbstractDcaCloner
{
    protected function table(): string
    {
        return 'tl_newsletter_channel';
    }

    protected function nameField(): string
    {
        return 'title';
    }

    protected function childTables(array $options): array
    {
        return ($options['recipients'] ?? false)
            ? ['tl_newsletter', 'tl_newsletter_recipients']
            : ['tl_newsletter'];
    }

    protected function derivedValues(string $table, array $row): array
    {
        return 'tl_newsletter' === $table ? ['alias' => $this->uniqueAlias('tl_newsletter', (string) ($row['subject'] ?? ''))] : [];
    }

    protected function versionsFor(string $table): bool
    {
        return 'tl_newsletter_recipients' !== $table;
    }

    protected function extraResult(array $options): array
    {
        return ['recipients_copied' => (bool) ($options['recipients'] ?? false)];
    }
}
