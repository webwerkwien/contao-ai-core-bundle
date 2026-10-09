<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:page:move', description: 'Move a Contao page: --to a parent or --after a sibling')]
class PageMoveCommand extends PageUpdateCommand
{
    use MovesRecord;
}
