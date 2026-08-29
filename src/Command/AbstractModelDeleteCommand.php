<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

abstract class AbstractModelDeleteCommand extends AbstractWriteCommand
{
    abstract protected function modelClass(): string;
    abstract protected function entityName(): string;

    public function __construct(protected readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, $this->entityName() . ' ID');
    }

    protected function doExecute(array $fields): int  // $fields intentionally unused — delete takes no field input
    {
        $this->framework->initialize();
        $id     = (int) $this->input->getArgument('id');
        $class  = $this->modelClass();
        $record = $class::findById($id);

        if ($record === null) {
            return $this->outputError($this->entityName() . " not found: $id");
        }

        // The cascade, the undo snapshot and the delete order live in the writer
        // (see RecordWriterInterface). What stays here is what this command knows
        // and the writer does not: which model class it speaks for, and who is
        // acting. `resolveOperatorUserId(0)` is the back-end user the undo entry is
        // filed under — 0 for a plain CLI deletion, which has no back-end user.
        $result = $this->writer()->delete(
            $class::getTable(),
            $id,
            $this->resolveOperator(),
            $this->resolveOperatorUserId(0),
        );

        $this->outputSuccess([
            'id'       => $id,
            'deleted'  => true,
            'undoable' => true,
        ] + $result);

        return Command::SUCCESS;
    }
}
