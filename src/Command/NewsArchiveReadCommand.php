<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsArchiveModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news-archive:read', description: 'Read a news archive record as JSON')]
class NewsArchiveReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return NewsArchiveModel::class; }
    protected function entityName(): string { return 'News archive'; }
}
