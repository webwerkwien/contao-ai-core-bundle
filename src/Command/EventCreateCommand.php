<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

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
        $this->addOption('endDate',   null, InputOption::VALUE_OPTIONAL, 'End date (Y-m-d)', date('Y-m-d'));
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = $this->input->getOption('title');
        $pid   = $this->input->getOption('pid');
        if (!$title || !$pid) {
            return $this->outputError('--title and --pid are required');
        }

        $event            = new CalendarEventsModel();
        $event->tstamp    = time();
        $event->pid       = (int) $pid;
        $event->title     = $title;
        $event->alias     = StringUtil::generateAlias($title);
        $event->startDate = strtotime($this->input->getOption('startDate'));
        $event->endDate   = strtotime($this->input->getOption('endDate'));
        $event->startTime = $event->startDate;
        $event->endTime   = $event->endDate;
        $event->published = '1';
        $event->author    = 1;

        foreach ($fields as $key => $value) {
            $event->$key = $value;
        }
        $event->save();
        $this->createVersion('tl_calendar_events', (int) $event->id);

        $this->outputSuccess(['id' => (int) $event->id, 'title' => $title]);
        return 0;
    }
}
