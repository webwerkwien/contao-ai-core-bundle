<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:page:update', description: 'Update a Contao page')]
class PageUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return PageModel::class; }
    protected function entityName(): string { return 'Page'; }
    protected function tableName(): string  { return 'tl_page'; }
}
