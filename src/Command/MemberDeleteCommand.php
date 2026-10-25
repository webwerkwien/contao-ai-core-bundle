<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:member:delete', description: 'Delete a frontend member')]
class MemberDeleteCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED, 'Username to delete');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $username = $this->input->getArgument('username');

        $member = MemberModel::findByUsername($username);
        if ($member === null) {
            return $this->outputError("Member not found: $username");
        }

        $id = (int) $member->id;

        // 🔴 This used to say `$member->delete()` — a bare
        // Contao\Model::delete(). No tl_undo snapshot, no cascade, no
        // system log: the command reported `deleted` and `contao:undo:restore`
        // could not bring the record back.
        //
        // 🎯 The writer has existed since v0.2.16 for exactly this — "the one place a
        // record is persisted, together with everything a write owes the audit
        // trail". These two commands went past it because they look up the
        // record by username and therefore could never extend
        // AbstractModelDeleteCommand. The shortcut cost the
        // recoverability.
        //
        // `undoable` goes into the answer only AFTER the snapshot: a promise
        // written before its proof is once again just a claim.
        $result = $this->writer()->delete(
            MemberModel::getTable(),
            $id,
            $this->resolveOperator(),
            $this->resolveOperatorUserId(0),
        );

        $this->outputSuccess([
            'username'   => $username,
            'deleted_id' => $id,
            'undoable'   => true,
        ] + $result);
        return Command::SUCCESS;
    }
}
