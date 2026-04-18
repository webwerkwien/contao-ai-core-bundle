<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FaqModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:faq:create', description: 'Create a FAQ entry')]
class FaqCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('question', null, InputOption::VALUE_REQUIRED, 'FAQ question');
        $this->addOption('answer',   null, InputOption::VALUE_OPTIONAL, 'FAQ answer (HTML)', '');
        $this->addOption('pid',      null, InputOption::VALUE_REQUIRED, 'FAQ category ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $question = $this->input->getOption('question');
        $pid      = $this->input->getOption('pid');
        if (!$question || !$pid) {
            return $this->outputError('--question and --pid are required');
        }

        $faq          = new FaqModel();
        $faq->tstamp  = time();
        $faq->pid     = (int) $pid;
        $faq->question = $question;
        $faq->answer  = $this->input->getOption('answer');
        $faq->published = '1';
        $faq->author  = 1;

        foreach ($fields as $key => $value) {
            $faq->$key = $value;
        }
        $faq->save();

        $this->outputSuccess(['id' => (int) $faq->id, 'question' => $question]);
        return 0;
    }
}
