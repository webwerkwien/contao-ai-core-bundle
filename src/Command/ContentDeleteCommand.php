<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ContentModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:content:delete', description: 'Delete a content element')]
class ContentDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return ContentModel::class; }
    protected function entityName(): string { return 'Content element'; }
}
