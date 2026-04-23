<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ArticleModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:article:read', description: 'Read a Contao article record as JSON')]
class ArticleReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Article ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id      = (int) $this->input->getArgument('id');
        $article = ArticleModel::findById($id);

        if ($article === null) {
            return $this->outputError("Article not found: $id");
        }

        $this->outputRecord($article->row());
        return Command::SUCCESS;
    }
}
