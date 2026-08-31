<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsletterModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a newsletter.
 *
 * `tl_newsletter` has no `ctable`, so nothing cascades — this removes one
 * record and lands in `tl_undo`, restorable through `contao:undo:restore`.
 *
 * Deleting a newsletter that was already sent removes it from the front end
 * archive (the reader lists exactly the records with `sent=1`), but changes
 * nothing about the mails that went out. There is no undo for those, here or
 * anywhere else.
 */
#[AsCommand(name: 'contao:newsletter:delete', description: 'Delete a newsletter')]
class NewsletterDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return NewsletterModel::class; }
    protected function entityName(): string { return 'Newsletter'; }
}
