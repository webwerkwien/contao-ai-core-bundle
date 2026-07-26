<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

abstract class AbstractModelUpdateCommand extends AbstractWriteCommand
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

    /**
     * Hook for subclasses to transform field values before write — e.g. wrap
     * Contao input-unit fields like `tl_news.headline` in their canonical
     * serialized array shape so create and update produce identical column
     * payloads. Default: return $fields unchanged.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function preProcessFields(array $fields, object $record): array
    {
        return $fields;
    }

    /**
     * Default unit for inputUnit fields (headline) when neither an explicit
     * value nor an existing record value provides one. Subclasses override
     * where Contao's field default differs (e.g. News → h1).
     */
    protected function defaultInputUnit(): string
    {
        return 'h2';
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

        $fields = $this->preProcessFields($fields, $record);
        // inputUnit fields (headline): serialize to {value, unit}; unit via
        // <field>_unit companion / JSON value / existing record value / default.
        $fields = $this->convertInputUnitFields($class::getTable(), $fields, $this->defaultInputUnit(), $record);
        // Turn string UUIDs into binary for fileTree fields (singleSRC etc.) —
        // DCA-driven, so this covers every table's file-reference fields.
        $fields = $this->convertFileTreeFields($class::getTable(), $fields);

        foreach ($fields as $key => $value) {
            $record->$key = $value;
        }
        // createVersion snapshots pre-save state; tstamp marks the commit time.
        $this->createVersion($class::getTable(), $id);
        $record->tstamp = time();
        $record->save();

        $this->outputSuccess(['id' => $id, 'updated' => array_keys($fields)]);
        return Command::SUCCESS;
    }
}
