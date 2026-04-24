<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:page:delete', description: 'Delete a Contao page')]
class PageDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return PageModel::class; }
    protected function entityName(): string { return 'Page'; }
}
