<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ArticleModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:article:update', description: 'Update a Contao article')]
class ArticleUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return ArticleModel::class; }
    protected function entityName(): string { return 'Article'; }
    protected function tableName(): string  { return 'tl_article'; }
}
