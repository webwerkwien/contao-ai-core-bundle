<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\UserGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:user-group:read', description: 'Read a back end user group as JSON')]
class UserGroupReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return UserGroupModel::class; }
    protected function entityName(): string { return 'User group'; }
}
