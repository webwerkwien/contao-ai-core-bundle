<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:content:delete', description: 'Delete a content element')]
class ContentDeleteCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'Content element ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id = (int) $this->input->getArgument('id');
        $el = ContentModel::findById($id);

        if ($el === null) {
            return $this->outputError("Content element not found: $id");
        }

        $el->delete();
        $this->outputSuccess(['id' => $id, 'deleted' => true]);
        return Command::SUCCESS;
    }
}
