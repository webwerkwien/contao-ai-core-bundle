<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:news:create', description: 'Create a news entry')]
class NewsCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('headline', null, InputOption::VALUE_REQUIRED, 'News headline');
        $this->addOption('pid',      null, InputOption::VALUE_REQUIRED, 'News archive ID');
        // Default 'now' (full current timestamp) instead of 'Y-m-d' midnight,
        // so two news created via the agent on the same day get distinct
        // `date` values — sorting by `date DESC` then yields the actual
        // creation order. Same-day-tie was the trap behind the 2026-05-01
        // "neueste" misinterpretation. Users can still pass an explicit date
        // string ("2026-06-01", "tomorrow", "2026-06-01 10:00") via --date.
        $this->addOption('date',     null, InputOption::VALUE_OPTIONAL, 'Publication date/time, accepts strtotime() format (default: now)', 'now');
        $this->addOption('unit',     null, InputOption::VALUE_OPTIONAL, 'Headline level (h1–h6)', 'h1');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $headline = $this->input->getOption('headline');
        $pid      = $this->input->getOption('pid');
        if (!$headline || !$pid) {
            return $this->outputError('--headline and --pid are required');
        }

        $news           = new NewsModel();
        $news->tstamp   = time();
        $news->pid      = (int) $pid;
        $unit = (string) $this->input->getOption('unit');
        if (!\in_array($unit, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $unit = 'h1';
        }
        // Canonical Contao order: value first, then unit (matches backend/SQL default).
        $news->headline = serialize(['value' => $headline, 'unit' => $unit]);
        $news->alias    = StringUtil::generateAlias($headline);
        $news->date     = strtotime($this->input->getOption('date'));
        $news->time     = $news->date;
        $news->published = '0';
        $news->author   = $this->resolveAuthorId();

        foreach ($fields as $key => $value) {
            $news->$key = $value;
        }
        $news->save();
        $this->createVersion('tl_news', (int) $news->id);

        $this->outputSuccess(['id' => (int) $news->id, 'headline' => $headline]);
        return Command::SUCCESS;
    }
}
