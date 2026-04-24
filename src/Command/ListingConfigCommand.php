<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:listing:config', description: 'Return listing module configuration from tl_module')]
class ListingConfigCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'tl_module ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        $id = (int) $this->input->getArgument('id');

        $module = ModuleModel::findById($id);
        if ($module === null) {
            return $this->outputError("Module not found: $id");
        }
        if ($module->type !== 'listing') {
            return $this->outputError("Module $id is not a listing module (type: {$module->type})");
        }

        $this->outputRecord($module->row());
        return Command::SUCCESS;
    }
}
