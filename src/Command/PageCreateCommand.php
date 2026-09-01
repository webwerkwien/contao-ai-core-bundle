<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:page:create', description: 'Create a Contao page')]
class PageCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title',    null, InputOption::VALUE_REQUIRED, 'Page title');
        $this->addOption('type',     null, InputOption::VALUE_OPTIONAL, 'Page type (regular, root, …)', 'regular');
        $this->addOption('pid',      null, InputOption::VALUE_OPTIONAL, 'Parent page ID', 0);
        $this->addOption('alias',    null, InputOption::VALUE_OPTIONAL, 'Page alias (auto-generated if omitted)', '');
        $this->addOption('language', null, InputOption::VALUE_OPTIONAL, 'Page language', 'de');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = $this->input->getOption('title');
        if (!$title) {
            return $this->outputError('--title is required');
        }

        $fields = $this->preparedFields('tl_page', [
            'pid'      => (int) $this->input->getOption('pid'),
            'title'    => $title,
            'type'     => $this->input->getOption('type'),
            'language' => $this->input->getOption('language'),
            'alias'    => $this->resolveAlias('tl_page', (string) $this->input->getOption('alias'), $title),
            'cuser'    => $this->resolveAuthorId(),
            // cgroup deliberately left at 0 — Contao's chmod system treats 0 as
            // "no group ownership"; setting an arbitrary group could grant or
            // deny access incorrectly. Admins can adjust via the regular backend
            // module if a group ACL is needed.
            'cgroup'    => 0,
            'published' => '0',
        ], $fields);

        $page         = new PageModel();
        $page->tstamp = time();

        foreach ($fields as $key => $value) {
            $page->$key = $value;
        }
        $page->save();
        $this->createVersion('tl_page', (int) $page->id);

        $this->outputSuccess(['id' => (int) $page->id, 'title' => $page->title, 'alias' => $page->alias]);
        return Command::SUCCESS;
    }
}
