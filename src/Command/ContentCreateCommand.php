<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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

        // headline is an inputUnit field ({value, unit}); --set headline_unit=h1
        // (or a JSON value) controls the level, default h2. Runs first because
        // it reads a companion key out of --set that must not reach the record.
        $fields = $this->convertInputUnitFields('tl_content', $fields, 'h2');

        // The command's own options travel with the --set pairs, so the DCA
        // rules see the whole record. singleSRC and other fileTree fields get
        // their binary UUID on the way through.
        $fields = $this->preparedFields('tl_content', [
            'pid'    => (int) $pid,
            'ptable' => $this->input->getOption('ptable'),
            'type'   => $type,
            // invisible is a boolean column; '' fails under strict SQL mode
            // (MariaDB rejects the empty string for an integer field).
            'invisible' => 0,
        ], $fields);

        $el         = new ContentModel();
        $el->tstamp = time();

        foreach ($fields as $key => $value) {
            $el->$key = $value;
        }
        $el->save();
        $this->createVersion('tl_content', (int) $el->id);

        $this->outputSuccess(['id' => (int) $el->id, 'type' => $el->type]);
        return Command::SUCCESS;
    }
}
