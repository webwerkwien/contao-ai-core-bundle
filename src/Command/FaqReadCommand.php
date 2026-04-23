<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FaqModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:faq:read', description: 'Read a Contao FAQ entry as JSON')]
class FaqReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'FAQ ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id  = (int) $this->input->getArgument('id');
        $faq = FaqModel::findById($id);

        if ($faq === null) {
            return $this->outputError("FAQ entry not found: $id");
        }

        $this->outputRecord($faq->row());
        return Command::SUCCESS;
    }
}
