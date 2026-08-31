<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\UserGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a back end user group.
 *
 * No cascade: `tl_user_group` declares no `ctable`, so nothing is deleted with
 * it. What it does leave behind is a dangling reference — `tl_user.groups` is a
 * list of group IDs, and Contao does not clean those up either. The users stay,
 * they simply lose the permissions this group carried.
 *
 * That is Contao's own behaviour, not an omission here, so the delete matches
 * it rather than inventing a cleanup the back end does not perform. The row
 * goes to `tl_undo` like any other, so the group itself is restorable.
 */
#[AsCommand(name: 'contao:user-group:delete', description: 'Delete a back end user group')]
class UserGroupDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return UserGroupModel::class; }
    protected function entityName(): string { return 'User group'; }
}
