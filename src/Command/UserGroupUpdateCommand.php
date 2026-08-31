<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\UserGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Update a back end user group.
 *
 * ⚠️ `--set modules=page` **replaces** the module list, it does not add to it.
 * That is how every multi-value field in this bundle behaves, and it matters
 * more here than elsewhere: a caller meaning to grant one module and passing
 * only that one revokes every other. Read the group first, then write the full
 * list you want it to end with.
 */
#[AsCommand(name: 'contao:user-group:update', description: 'Update a back end user group')]
class UserGroupUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return UserGroupModel::class; }
    protected function entityName(): string { return 'User group'; }
}
