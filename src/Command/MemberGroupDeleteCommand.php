<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\MemberGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a front end member group.
 *
 * No cascade, and — as with user groups — Contao leaves the references behind:
 * `tl_member.groups`, and every `groups` field on a protected page, article or
 * content element, keeps the dead ID. The practical effect is that protected
 * content stays protected but nobody is in the group any more, which is the
 * safe direction to fail in.
 */
#[AsCommand(name: 'contao:member-group:delete', description: 'Delete a front end member group')]
class MemberGroupDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return MemberGroupModel::class; }
    protected function entityName(): string { return 'Member group'; }
}
