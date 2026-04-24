<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

abstract class AbstractModelReadCommand extends AbstractReadCommand
{
    /** FQCN of the Contao Model class, e.g. \Contao\ArticleModel::class */
    abstract protected function modelClass(): string;

    /** Human-readable entity name for error messages, e.g. 'Article' */
    abstract protected function entityName(): string;

    public function __construct(protected readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, $this->entityName() . ' ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        $id    = (int) $this->input->getArgument('id');
        $class = $this->modelClass();
        $record = $class::findById($id);

        if ($record === null) {
            return $this->outputError($this->entityName() . " not found: $id");
        }

        $this->outputRecord($this->postProcessRow($record->row()));
        return Command::SUCCESS;
    }

    /**
     * Override to transform the raw row before output.
     * Default: return row unchanged.
     */
    protected function postProcessRow(array $row): array
    {
        return $row;
    }
}
