<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CommentsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:comment:delete', description: 'Delete a comment')]
class CommentDeleteCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'Comment ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id      = (int) $this->input->getArgument('id');
        $comment = CommentsModel::findById($id);

        if ($comment === null) {
            return $this->outputError("Comment not found: $id");
        }

        $comment->delete();
        $this->outputSuccess(['id' => $id, 'deleted' => true]);
        return Command::SUCCESS;
    }
}
