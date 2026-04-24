<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\NewsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news:update', description: 'Update a news entry')]
class NewsUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return NewsModel::class; }
    protected function entityName(): string { return 'News entry'; }
}
