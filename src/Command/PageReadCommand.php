<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:page:read', description: 'Read a Contao page record as JSON')]
class PageReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Page ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id   = (int) $this->input->getArgument('id');
        $page = PageModel::findById($id);

        if ($page === null) {
            return $this->outputError("Page not found: $id");
        }

        $row = $page->row();

        // Resolve effective layout (walk up page tree if layout = 0)
        $layoutId = (int) $row['layout'];
        if ($layoutId === 0) {
            $parent = PageModel::findById((int) $row['pid']);
            while ($parent !== null && (int) $parent->layout === 0) {
                $parent = PageModel::findById((int) $parent->pid);
            }
            $layoutId = $parent !== null ? (int) $parent->layout : 0;
        }
        $row['layout_effective'] = $layoutId;

        $this->outputRecord($row);
        return 0;
    }
}
