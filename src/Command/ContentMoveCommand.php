<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:content:move', description: 'Move a content element: --to a parent or --after a sibling')]
class ContentMoveCommand extends ContentUpdateCommand
{
    use MovesRecord;

    protected function hasPtableOption(): bool
    {
        return true;
    }
}
