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
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $headline = $this->input->getOption('headline');
        $pid      = $this->input->getOption('pid');
        if (!$headline || !$pid) {
            return $this->outputError('--headline and --pid are required');
        }

        $date = strtotime($this->input->getOption('date'));

        $fields = $this->preparedFields('tl_news', [
            'pid' => (int) $pid,
            // tl_news.headline is a PLAIN TEXT field — it is the news *title*, not a
            // headline element. Contao's DCA (news-bundle/contao/dca/tl_news.php):
            //     'inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"
            // and Contao reads it verbatim (NewsFeedListener::setTitle($article->headline),
            // InsertTag 'news_title'). Only tl_content.headline is an `inputUnit` field
            // with a serialized {value, unit} payload. Writing a serialized array here
            // put the raw `a:2:{…}` string into every listing, feed and front end title
            // — see NewsRepairHeadlinesCommand for repairing records written that way.
            'headline'  => (string) $headline,
            'alias'     => $this->resolveAlias('tl_news', '', (string) $headline),
            'date'      => $date,
            'time'      => $date,
            'published' => '0',
            'author'    => $this->resolveAuthorId(),
        ], $fields);

        $news         = new NewsModel();
        $news->tstamp = time();

        foreach ($fields as $key => $value) {
            $news->$key = $value;
        }
        $news->save();
        $this->createVersion('tl_news', (int) $news->id);

        $this->outputSuccess(['id' => (int) $news->id, 'headline' => $headline]);
        return Command::SUCCESS;
    }
}
