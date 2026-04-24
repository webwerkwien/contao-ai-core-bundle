<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ArticleModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:article:delete', description: 'Delete a Contao article')]
class ArticleDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return ArticleModel::class; }
    protected function entityName(): string { return 'Article'; }
}
