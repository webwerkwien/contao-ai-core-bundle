<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsletterRecipientsModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Remove a recipient from a newsletter channel.
 *
 * `tl_newsletter_recipients` has no `ctable`, so nothing cascades. The row
 * lands in `tl_undo` and stays restorable through `contao:undo:restore`.
 *
 * ⚠️ **Deleting is not unsubscribing.** Contao's unsubscribe path writes an
 * entry to `tl_newsletter_deny_list` so the address cannot be re-added by an
 * import; deleting the row here removes the recipient but creates no such
 * entry, so nothing stops the same address being added again tomorrow.
 *
 * For an actual opt-out request, deactivating (`recipient update <id> --set
 * active=0`) or the back end's block action is the honest instrument — the
 * latter is what writes the deny list entry. This command is for the ordinary
 * case: a wrong address, a test entry, a list being tidied.
 */
#[AsCommand(name: 'contao:newsletter-recipient:delete', description: 'Remove a recipient from a newsletter channel')]
class NewsletterRecipientDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return NewsletterRecipientsModel::class; }
    protected function entityName(): string { return 'Newsletter recipient'; }
}
