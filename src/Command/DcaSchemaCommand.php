<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:dca:schema', description: 'Return DCA field definitions for a table')]
class DcaSchemaCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('table', InputArgument::REQUIRED, 'DCA table name (e.g. tl_news)');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        $table = $this->input->getArgument('table');

        Controller::loadDataContainer($table);

        if (!isset($GLOBALS['TL_DCA'][$table])) {
            return $this->outputError("DCA not found for table: $table");
        }

        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $result = [];

        foreach ($fields as $name => $def) {
            $result[$name] = [
                'label'     => $def['label'][0] ?? $name,
                'inputType' => $def['inputType'] ?? null,
                'mandatory' => (bool) ($def['eval']['mandatory'] ?? false),
                'unique'    => (bool) ($def['eval']['unique'] ?? false),
                'maxlength' => $def['eval']['maxlength'] ?? null,
                'options'   => isset($def['options']) && is_array($def['options']) ? array_keys($def['options']) : null,
            ];
        }

        $this->outputRecord(['table' => $table, 'fields' => $result]);
        return Command::SUCCESS;
    }
}
