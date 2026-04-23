<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CommentsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:comment:publish', description: 'Publish or unpublish a comment')]
class CommentPublishCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id',     InputArgument::REQUIRED, 'Comment ID');
        $this->addArgument('action', InputArgument::OPTIONAL, 'publish or unpublish', 'publish');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id      = (int) $this->input->getArgument('id');
        $action  = $this->input->getArgument('action');
        $comment = CommentsModel::findById($id);

        if ($comment === null) {
            return $this->outputError("Comment not found: $id");
        }
        if (!in_array($action, ['publish', 'unpublish'], true)) {
            return $this->outputError("Invalid action '$action'. Use: publish or unpublish");
        }

        $comment->published = ($action === 'publish') ? '1' : '';
        $comment->tstamp    = time();
        $comment->save();

        $this->outputSuccess(['id' => $id, 'published' => $comment->published === '1']);
        return Command::SUCCESS;
    }
}
