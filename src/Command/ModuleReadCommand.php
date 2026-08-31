<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ModuleModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:module:read', description: 'Read a front end module record as JSON')]
class ModuleReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return ModuleModel::class; }
    protected function entityName(): string { return 'Module'; }
}
