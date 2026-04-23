<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ArticleModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:article:update', description: 'Update a Contao article')]
class ArticleUpdateCommand extends AbstractWriteCommand
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
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        foreach ($fields as $key => $value) {
            $article->$key = $value;
        }
        $this->createVersion('tl_article', $id);
        $article->tstamp = time();
        $article->save();

        $this->outputSuccess(['id' => $id, 'updated' => array_keys($fields)]);
        return Command::SUCCESS;
    }
}
