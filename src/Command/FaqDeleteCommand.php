<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FaqModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:faq:delete', description: 'Delete a FAQ entry')]
class FaqDeleteCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'FAQ ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id  = (int) $this->input->getArgument('id');
        $faq = FaqModel::findById($id);

        if ($faq === null) {
            return $this->outputError("FAQ entry not found: $id");
        }

        $faq->delete();
        $this->outputSuccess(['id' => $id, 'deleted' => true]);
        return Command::SUCCESS;
    }
}
