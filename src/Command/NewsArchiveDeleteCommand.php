<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsArchiveModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a news archive and everything under it.
 *
 * The `ctable` chain is tl_news → tl_content, and RecordCascadeCollector
 * follows it, so this takes every news item in it and every content element inside those items with it.
 *
 * That is Contao's own behaviour — deleting the parent in the back end does
 * the same — but the size of it is not visible from the command name. The CLI
 * wrapper names the child tables in its prompt for that reason, the way
 * `theme delete` does.
 *
 * The whole set lands in a single `tl_undo` entry and stays restorable through
 * `contao:undo:restore`.
 */
#[AsCommand(name: 'contao:news-archive:delete', description: 'Delete a news archive with everything in it')]
class NewsArchiveDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return NewsArchiveModel::class; }
    protected function entityName(): string { return 'News archive'; }
}
