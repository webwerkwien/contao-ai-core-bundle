<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ArticleModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:article:delete', description: 'Delete a Contao article')]
class ArticleDeleteCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'Article ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id      = (int) $this->input->getArgument('id');
        $article = ArticleModel::findById($id);

        if ($article === null) {
            return $this->outputError("Article not found: $id");
        }

        $article->delete();
        $this->outputSuccess(['id' => $id, 'deleted' => true]);
        return 0;
    }
}
