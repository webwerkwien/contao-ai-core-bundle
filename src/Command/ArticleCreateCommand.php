<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ArticleModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:article:create', description: 'Create a Contao article')]
class ArticleCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title',    null, InputOption::VALUE_REQUIRED, 'Article title');
        $this->addOption('pid',      null, InputOption::VALUE_REQUIRED, 'Parent page ID');
        $this->addOption('inColumn', null, InputOption::VALUE_OPTIONAL, 'Layout column', 'main');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = $this->input->getOption('title');
        $pid   = $this->input->getOption('pid');
        if (!$title || !$pid) {
            return $this->outputError('--title and --pid are required');
        }

        $fields = $this->preparedFields('tl_article', [
            'pid'       => (int) $pid,
            'title'     => $title,
            'alias'     => $this->resolveAlias('tl_article', '', $title),
            'inColumn'  => $this->input->getOption('inColumn'),
            'author'    => $this->resolveAuthorId(),
            'published' => '0',
        ], $fields);

        $article         = new ArticleModel();
        $article->tstamp = time();

        foreach ($fields as $key => $value) {
            $article->$key = $value;
        }
        $article->save();
        $this->createVersion('tl_article', (int) $article->id);

        $this->outputSuccess(['id' => (int) $article->id, 'title' => $article->title]);
        return Command::SUCCESS;
    }
}
