<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:page:update', description: 'Update a Contao page')]
class PageUpdateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'Page ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id   = (int) $this->input->getArgument('id');
        $page = PageModel::findById($id);

        if ($page === null) {
            return $this->outputError("Page not found: $id");
        }
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        foreach ($fields as $key => $value) {
            $page->$key = $value;
        }
        $page->tstamp = time();
        $this->createVersion('tl_page', $id);
        $page->save();

        $this->outputSuccess(['id' => $id, 'updated' => array_keys($fields)]);
        return Command::SUCCESS;
    }
}
