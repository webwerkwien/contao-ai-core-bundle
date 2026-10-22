<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ArticleModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:article:create', description: 'Create a Contao article')]
class ArticleCreateCommand extends AbstractWriteCommand implements CreatesRecord
{
    use SiblingPositions;

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
        $this->addOption('after',    null, InputOption::VALUE_REQUIRED, 'Sibling article ID — the new article goes directly behind it, on the same page (instead of --pid)');
    }

    protected function doExecute(array $fields): int
    {
        $after = $this->afterOption(['pid']);
        if (\is_string($after)) {
            return $this->outputError($after);
        }

        $title = $this->input->getOption('title');
        $pid   = $this->input->getOption('pid');
        if (!$title || (!$pid && null === $after)) {
            return $this->outputError('--title and --pid (or --after <article ID>) are required');
        }

        $this->framework->initialize();

        // Directly behind the sibling, on its page (v1.12.0); otherwise behind the last
        // article of the page.
        try {
            $slot = null === $after ? null : $this->slotAfter('tl_article', $after, null, 'put the new article');
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }
        $pid = (int) ($slot['pid'] ?? $pid);

        $fields = $this->preparedFields('tl_article', [
            'pid'       => $pid,
            // Behind the last article of the page. Was 0 until v0.13.0.
            'sorting'   => $slot['sorting'] ?? $this->nextSorting('tl_article', $pid),
            'title'     => $title,
            'alias'     => $this->resolveAlias('tl_article', '', $title, record: ['title' => $title, 'pid' => $pid]),
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
        $this->createVersion('tl_article', (int) $article->id, created: true);

        $this->outputSuccess(['id' => (int) $article->id, 'title' => $article->title, 'alias' => (string) $article->alias]);
        return Command::SUCCESS;
    }
}
