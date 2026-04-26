<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:page:publish', description: 'Publish or unpublish a Contao page')]
class PagePublishCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id',     InputArgument::REQUIRED, 'Page ID');
        $this->addArgument('action', InputArgument::OPTIONAL, 'publish or unpublish', 'publish');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id     = (int) $this->input->getArgument('id');
        $action = $this->input->getArgument('action');
        $page   = PageModel::findById($id);

        if ($page === null) {
            return $this->outputError("Page not found: $id");
        }
        if (!in_array($action, ['publish', 'unpublish'], true)) {
            return $this->outputError("Invalid action '$action'. Use: publish or unpublish");
        }

        $page->published = ($action === 'publish') ? '1' : '';
        $page->tstamp    = time();
        $page->save();

        $this->outputSuccess(['id' => $id, 'published' => $page->published === '1']);
        return Command::SUCCESS;
    }
}
