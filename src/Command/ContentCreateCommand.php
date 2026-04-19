<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:content:create', description: 'Create a content element')]
class ContentCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('type',   null, InputOption::VALUE_REQUIRED, 'Element type (text, headline, …)');
        $this->addOption('pid',    null, InputOption::VALUE_REQUIRED, 'Parent ID (article ID)');
        $this->addOption('ptable', null, InputOption::VALUE_OPTIONAL, 'Parent table', 'tl_article');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $type = $this->input->getOption('type');
        $pid  = $this->input->getOption('pid');
        if (!$type || !$pid) {
            return $this->outputError('--type and --pid are required');
        }

        $el          = new ContentModel();
        $el->tstamp  = time();
        $el->pid     = (int) $pid;
        $el->ptable  = $this->input->getOption('ptable');
        $el->type    = $type;
        $el->invisible = '';

        foreach ($fields as $key => $value) {
            $el->$key = $value;
        }
        $el->save();
        $this->createVersion('tl_content', (int) $el->id);

        $this->outputSuccess(['id' => (int) $el->id, 'type' => $el->type]);
        return 0;
    }
}
