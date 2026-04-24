<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FaqModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:faq:update', description: 'Update a FAQ entry')]
class FaqUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return FaqModel::class; }
    protected function entityName(): string { return 'FAQ entry'; }
}
