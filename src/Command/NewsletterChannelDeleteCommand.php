<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsletterChannelModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a newsletter channel and everything under it.
 *
 * `tl_newsletter_channel` declares `ctable => [tl_newsletter,
 * tl_newsletter_recipients]`, and RecordCascadeCollector follows it — so this
 * takes **both** every newsletter in the channel **and its entire recipient
 * list** with it. Two child tables rather than the usual one, and the second is
 * the one nobody pictures when reading the command name.
 *
 * That is Contao's own behaviour; deleting the channel in the back end does the
 * same. The CLI wrapper names both child tables in its prompt for that reason.
 *
 * The whole set lands in a single `tl_undo` entry and stays restorable through
 * `contao:undo:restore` — which is worth knowing here, because a recipient list
 * is the one thing in this module that cannot be reconstructed from anywhere
 * else once it is gone.
 *
 * Note what is *not* cascaded: `tl_newsletter_deny_list` has no `ptable` and is
 * not in the `ctable` chain, so unsubscribe records survive the channel. That is
 * the right way round — a deny-list entry outliving its channel is harmless,
 * losing it would silently re-permit addresses.
 */
#[AsCommand(name: 'contao:newsletter-channel:delete', description: 'Delete a newsletter channel with its newsletters and recipients')]
class NewsletterChannelDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return NewsletterChannelModel::class; }
    protected function entityName(): string { return 'Newsletter channel'; }
}
