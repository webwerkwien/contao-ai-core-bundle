<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CommentsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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

        // 🔴 This used to say `$comment->published = …; $comment->save();` —
        // past the writer. Consequence: no tl_version entry, so an accidental
        // publish could not be taken back through the version history.
        //
        // 🎯 The writer takes finished values and handles snapshot, tstamp and
        // save. BUILDING values stays the command's job — exactly as the
        // contract of RecordWriterInterface says. These commands had nothing to
        // build and still saved on their own.
        $publish = 'publish' === $action;

        $this->writer()->update(
            CommentsModel::getTable(),
            $id,
            ['published' => $this->booleanFlag($publish)],
            $this->resolveOperator(),
        );

        $this->outputSuccess(['id' => $id, 'published' => $publish]);
        return Command::SUCCESS;
    }
}
