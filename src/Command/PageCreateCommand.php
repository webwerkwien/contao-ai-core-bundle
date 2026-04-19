<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
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

        $page           = new PageModel();
        $page->tstamp   = time();
        $page->pid      = (int) $this->input->getOption('pid');
        $page->title    = $title;
        $page->type     = $this->input->getOption('type');
        $page->language = $this->input->getOption('language');
        $page->alias    = $this->input->getOption('alias') ?: StringUtil::generateAlias($title);
        $page->published = '1';

        foreach ($fields as $key => $value) {
            $page->$key = $value;
        }
        $page->save();
        $this->createVersion('tl_page', (int) $page->id);

        $this->outputSuccess(['id' => (int) $page->id, 'title' => $page->title, 'alias' => $page->alias]);
        return 0;
    }
}
