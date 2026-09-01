<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsArchiveModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a news archive — the container a news item lives in.
 *
 * `contao:news:create` has existed for a long time and needed a `pid`. The
 * archive that `pid` points at could not be created, so the first news item on
 * a fresh install still meant opening the back end. Three modules had the same
 * gap in the same shape: **the child worked, the parent did not.**
 *
 * Only `--title` is a dedicated option. Everything else goes through `--set`,
 * and what is *required* is read from the DCA rather than hard-coded:
 *
 *   news-archive create --title "Blog"                  → refused, jumpTo missing
 *   news-archive create --title "Blog" --set jumpTo=7   → accepted
 *
 * `jumpTo` is mandatory and sits in the default palette, so it is always
 * needed — it is the page that renders a single item, and an archive without
 * one produces links to nowhere. `groups` is mandatory too, but only inside
 * the `protected` subpalette: demanding it always would refuse every public
 * archive. Both rules come from `missingMandatoryFields()`, so an extension
 * adding a mandatory field to this table is covered without a change here.
 */
#[AsCommand(name: 'contao:news-archive:create', description: 'Create a news archive')]
class NewsArchiveCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Archive title');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = (string) $this->input->getOption('title');

        if ('' === $title) {
            return $this->outputError('--title is required');
        }

        if (!isset($GLOBALS['TL_DCA']['tl_news_archive']['fields'])) {
            Controller::loadDataContainer('tl_news_archive');
        }

        $missing = $this->missingMandatoryFields('tl_news_archive', 'default', $fields, ['title']);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Also required: %s. Pass them with --set. (jumpTo is the page that renders '
                . 'a single news item; groups is only required for a protected archive.)',
                implode(', ', $missing),
            ));
        }

        $fields = $this->preparedFields('tl_news_archive', [
            'title' => $title,
        ], $fields);

        $archive         = new NewsArchiveModel();
        $archive->tstamp = time();

        foreach ($fields as $key => $value) {
            $archive->$key = $value;
        }

        $archive->save();
        $this->createVersion('tl_news_archive', (int) $archive->id);

        $this->outputSuccess(['id' => (int) $archive->id, 'title' => $title]);

        return Command::SUCCESS;
    }
}
