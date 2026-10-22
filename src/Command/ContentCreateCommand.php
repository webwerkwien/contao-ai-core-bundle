<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:content:create', description: 'Create a content element')]
class ContentCreateCommand extends AbstractWriteCommand implements CreatesRecord
{
    use SiblingPositions;

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
        $this->addOption('after',  null, InputOption::VALUE_REQUIRED, 'Sibling element ID — the new element goes directly behind it, below the same parent (instead of --pid)');
    }

    protected function doExecute(array $fields): int
    {
        $after = $this->afterOption(['pid', 'ptable']);
        if (\is_string($after)) {
            return $this->outputError($after);
        }

        $type = $this->input->getOption('type');
        $pid  = $this->input->getOption('pid');
        if (!$type || (!$pid && null === $after)) {
            return $this->outputError('--type and --pid (or --after <element ID>) are required');
        }

        $this->framework->initialize();

        // Directly behind the sibling, below its parent and parent table (v1.12.0);
        // otherwise behind the last element of the parent.
        try {
            $slot = null === $after ? null : $this->slotAfter('tl_content', $after, null, 'put the new element');
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }
        $ptable = (string) ($slot['ptable'] ?? $this->input->getOption('ptable'));
        $pid    = (int) ($slot['pid'] ?? $pid);

        // headline is an inputUnit field ({value, unit}); --set headline_unit=h1
        // (or a JSON value) controls the level, default h2. Runs first because
        // it reads a companion key out of --set that must not reach the record.
        $fields = $this->convertInputUnitFields('tl_content', $fields, 'h2');

        // The command's own options travel with the --set pairs, so the DCA
        // rules see the whole record. singleSRC and other fileTree fields get
        // their binary UUID on the way through.
        $fields = $this->preparedFields('tl_content', [
            'pid'    => $pid,
            'ptable' => $ptable,
            // Behind the last element of the same parent — pid *and* ptable, since
            // tl_content hangs under articles, news, events and more. Was 0 until
            // v0.13.0, which left the order on the page to the database.
            'sorting' => $slot['sorting'] ?? $this->nextSorting('tl_content', $pid, $ptable),
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
        $this->createVersion('tl_content', (int) $el->id, created: true);

        $this->outputSuccess(['id' => (int) $el->id, 'type' => $el->type]);
        return Command::SUCCESS;
    }
}
