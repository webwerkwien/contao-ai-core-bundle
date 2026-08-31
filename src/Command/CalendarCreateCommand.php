<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CalendarModel;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a calendar — the container an event lives in.
 *
 * The same gap as news archives, in the same shape: `contao:event:create`
 * needed a `pid` and the calendar behind it could not be created.
 *
 * `tl_calendar` is `tl_news_archive` with different labels — `title` and
 * `jumpTo` mandatory in the default palette, `groups` mandatory only inside
 * the `protected` subpalette. The rule is read from the DCA, so the two
 * commands do not carry a copy of it each.
 *
 * Note that `jumpTo` here points at the page rendering a single **event**, and
 * that a calendar with no `jumpTo` is refused for the same reason an archive
 * is: every link the module generates would go nowhere.
 */
#[AsCommand(name: 'contao:calendar:create', description: 'Create a calendar')]
class CalendarCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Calendar title');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = (string) $this->input->getOption('title');

        if ('' === $title) {
            return $this->outputError('--title is required');
        }

        if (!isset($GLOBALS['TL_DCA']['tl_calendar']['fields'])) {
            Controller::loadDataContainer('tl_calendar');
        }

        $missing = $this->missingMandatoryFields('tl_calendar', 'default', $fields, ['title']);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Also required: %s. Pass them with --set. (jumpTo is the page that renders '
                . 'a single event; groups is only required for a protected calendar.)',
                implode(', ', $missing),
            ));
        }

        $fields = $this->convertFields('tl_calendar', $fields);

        $calendar         = new CalendarModel();
        $calendar->tstamp = time();
        $calendar->title  = $title;

        foreach ($fields as $key => $value) {
            $calendar->$key = $value;
        }

        $calendar->save();
        $this->createVersion('tl_calendar', (int) $calendar->id);

        $this->outputSuccess(['id' => (int) $calendar->id, 'title' => $title]);

        return Command::SUCCESS;
    }
}
