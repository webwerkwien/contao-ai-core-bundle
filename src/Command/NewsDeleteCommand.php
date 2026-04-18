<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:news:delete', description: 'Delete a news entry')]
class NewsDeleteCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'News ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id   = (int) $this->input->getArgument('id');
        $news = NewsModel::findById($id);

        if ($news === null) {
            return $this->outputError("News entry not found: $id");
        }

        $news->delete();
        $this->outputSuccess(['id' => $id, 'deleted' => true]);
        return 0;
    }
}
