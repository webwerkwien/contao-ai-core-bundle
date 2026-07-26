<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news:update', description: 'Update a news entry')]
class NewsUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return NewsModel::class; }
    protected function entityName(): string { return 'News entry'; }

    // headline (inputUnit) is handled generically in AbstractModelUpdateCommand
    // via convertInputUnitFields(); tl_news.headline defaults to h1 (article
    // title) when neither an explicit nor an existing unit is present.
    protected function defaultInputUnit(): string { return 'h1'; }
}
