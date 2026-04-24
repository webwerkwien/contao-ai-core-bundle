<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ArticleModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:article:read', description: 'Read a Contao article record as JSON')]
class ArticleReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return ArticleModel::class; }
    protected function entityName(): string { return 'Article'; }
}
