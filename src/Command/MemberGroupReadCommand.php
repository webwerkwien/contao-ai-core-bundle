<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\MemberGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:member-group:read', description: 'Read a front end member group as JSON')]
class MemberGroupReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return MemberGroupModel::class; }
    protected function entityName(): string { return 'Member group'; }
}
