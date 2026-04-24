<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

abstract class AbstractModelUpdateCommand extends AbstractWriteCommand
{
    abstract protected function modelClass(): string;
    abstract protected function entityName(): string;
    abstract protected function tableName(): string;

    public function __construct(protected readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, $this->entityName() . ' ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id    = (int) $this->input->getArgument('id');
        $class = $this->modelClass();
        $record = $class::findById($id);

        if ($record === null) {
            return $this->outputError($this->entityName() . " not found: $id");
        }
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        foreach ($fields as $key => $value) {
            $record->$key = $value;
        }
        $this->createVersion($this->tableName(), $id);
        $record->tstamp = time();
        $record->save();

        $this->outputSuccess(['id' => $id, 'updated' => array_keys($fields)]);
        return Command::SUCCESS;
    }
}
