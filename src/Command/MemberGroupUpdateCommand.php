<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\MemberGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:member-group:update', description: 'Update a front end member group')]
class MemberGroupUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return MemberGroupModel::class; }
    protected function entityName(): string { return 'Member group'; }
}
