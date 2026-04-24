<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ContentModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:content:update', description: 'Update a content element')]
class ContentUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return ContentModel::class; }
    protected function entityName(): string { return 'Content element'; }
}
