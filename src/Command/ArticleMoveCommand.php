<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:article:move', description: 'Move a Contao article: --to a parent or --after a sibling')]
class ArticleMoveCommand extends ArticleUpdateCommand
{
    use MovesRecord;
}
