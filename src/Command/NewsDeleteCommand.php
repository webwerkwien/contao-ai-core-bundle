<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news:delete', description: 'Delete a news entry')]
class NewsDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return NewsModel::class; }
    protected function entityName(): string { return 'News entry'; }
}
