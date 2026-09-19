<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Calendar\EventTimes;

#[AsCommand(name: 'contao:event:create', description: 'Create a calendar event')]
class EventCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title',     null, InputOption::VALUE_REQUIRED, 'Event title');
        $this->addOption('pid',       null, InputOption::VALUE_REQUIRED, 'Calendar ID');
        $this->addOption('startDate', null, InputOption::VALUE_OPTIONAL, 'Start date (Y-m-d)', date('Y-m-d'));
        // No default: a single day event has no end date in Contao. Until v0.28.0
        // this defaulted to the day of the call, which ended every event created
        // without it on that day (practical test 2026-09-19, see EventTimes).
        $this->addOption('endDate',   null, InputOption::VALUE_OPTIONAL, 'End date (Y-m-d), only for events over several days');
        $this->addOption('startTime', null, InputOption::VALUE_OPTIONAL, 'Start time (H:i) — makes it an event with a time');
        $this->addOption('endTime',   null, InputOption::VALUE_OPTIONAL, 'End time (H:i), needs --startTime');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = $this->input->getOption('title');
        $pid   = $this->input->getOption('pid');
        if (!$title || !$pid) {
            return $this->outputError('--title and --pid are required');
        }

        $startTime = (string) ($this->input->getOption('startTime') ?? '');
        $endTime   = (string) ($this->input->getOption('endTime') ?? '');
        $endOption = (string) ($this->input->getOption('endDate') ?? '');

        if ('' !== $endTime && '' === $startTime) {
            return $this->outputError('--endTime needs --startTime. An event without a start time lasts all day.');
        }

        try {
            $startDate = EventTimes::day((string) $this->input->getOption('startDate'));
            $endDate   = '' !== $endOption ? EventTimes::day($endOption) : null;
            // Named even when empty: startTime and endTime have the DCA default
            // time(), which preparedFields() would otherwise add as a time of day
            // on an all-day event (v0.28.0, caught before release). EventTimes
            // derives both below.
            $own       = ['startTime' => null, 'endTime' => null];

            if ('' !== $startTime) {
                $own = [
                    'addTime'   => '1',
                    'startTime' => EventTimes::timeOfDay($startTime),
                    'endTime'   => '' !== $endTime ? EventTimes::timeOfDay($endTime) : null,
                ];
            }
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        $fields = $this->preparedFields('tl_calendar_events', [
            'pid'       => (int) $pid,
            'title'     => $title,
            'alias'     => $this->resolveAlias('tl_calendar_events', '', $title, record: ['title' => $title, 'pid' => (int) $pid]),
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'published' => '0',
            'author'    => $this->resolveAuthorId(),
        ] + $own, $fields);

        if (null !== $refusal = EventTimes::refusal($fields)) {
            return $this->outputError($refusal);
        }

        // What the back end's onsubmit callback derives from the dates, applied to
        // the merged values so `--set addTime=1 --set startTime=…` works as well.
        $fields = EventTimes::adjust($fields) + $fields;

        $event         = new CalendarEventsModel();
        $event->tstamp = time();

        foreach ($fields as $key => $value) {
            $event->$key = $value;
        }
        $event->save();
        $this->createVersion('tl_calendar_events', (int) $event->id, created: true);

        $this->outputSuccess([
            'id'        => (int) $event->id,
            'title'     => $title,
            'alias'     => (string) $event->alias,
            'startTime' => null !== $event->startTime ? (int) $event->startTime : null,
            'endTime'   => null !== $event->endTime ? (int) $event->endTime : null,
        ]);
        return Command::SUCCESS;
    }
}
