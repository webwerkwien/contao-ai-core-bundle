<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FaqCategoryModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a FAQ category and everything under it.
 *
 * The `ctable` chain is tl_faq, and RecordCascadeCollector
 * follows it, so this takes every question in it with it.
 *
 * That is Contao's own behaviour — deleting the parent in the back end does
 * the same — but the size of it is not visible from the command name. The CLI
 * wrapper names the child tables in its prompt for that reason, the way
 * `theme delete` does.
 *
 * The whole set lands in a single `tl_undo` entry and stays restorable through
 * `contao:undo:restore`.
 */
#[AsCommand(name: 'contao:faq-category:delete', description: 'Delete a FAQ category with everything in it')]
class FaqCategoryDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return FaqCategoryModel::class; }
    protected function entityName(): string { return 'FAQ category'; }
}
