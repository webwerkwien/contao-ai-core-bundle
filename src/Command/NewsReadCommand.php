<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news:read', description: 'Read a Contao news entry as JSON')]
class NewsReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return NewsModel::class; }
    protected function entityName(): string { return 'News entry'; }

    // No postProcessRow(): tl_news.headline is a plain text field (the news
    // title). An earlier version deserialized it into {value, unit}, which
    // masked the fact that NewsCreateCommand wrote a serialized array into a
    // column Contao renders verbatim — reading it back looked correct while
    // the front end showed `a:2:{…}`. Returning the raw column value keeps the
    // read path honest; legacy records can be fixed with
    // `contao:news:repair-headlines`.
}
