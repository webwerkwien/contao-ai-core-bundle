<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CommentsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:comment:delete', description: 'Delete a comment')]
class CommentDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return CommentsModel::class; }
    protected function entityName(): string { return 'Comment'; }
}
