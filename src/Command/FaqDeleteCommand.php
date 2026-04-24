<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\FaqModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:faq:delete', description: 'Delete a FAQ entry')]
class FaqDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return FaqModel::class; }
    protected function entityName(): string { return 'FAQ entry'; }
}
