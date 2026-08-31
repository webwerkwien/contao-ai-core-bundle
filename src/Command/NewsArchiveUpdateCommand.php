<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsArchiveModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news-archive:update', description: 'Update a news archive')]
class NewsArchiveUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return NewsArchiveModel::class; }
    protected function entityName(): string { return 'News archive'; }
}
