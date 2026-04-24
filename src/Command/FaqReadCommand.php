<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\FaqModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:faq:read', description: 'Read a Contao FAQ entry as JSON')]
class FaqReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return FaqModel::class; }
    protected function entityName(): string { return 'FAQ entry'; }
}
