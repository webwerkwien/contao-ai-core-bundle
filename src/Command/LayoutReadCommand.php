<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\LayoutModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:layout:read', description: 'Read a Contao layout record as JSON')]
class LayoutReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Layout ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id     = (int) $this->input->getArgument('id');
        $layout = LayoutModel::findById($id);

        if ($layout === null) {
            return $this->outputError("Layout not found: $id");
        }

        $this->outputRecord($layout->row());
        return 0;
    }
}
