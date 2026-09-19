<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CalendarEventsModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Calendar\EventTimes;

/**
 * Update a calendar event — and derive its stored times the way the back end does.
 *
 * Changing a date or a time goes through `EventTimes`, which mirrors the
 * `adjustTime()` onsubmit callback this write path does not run (v0.28.0). Until
 * then `--set startDate=…` moved the day the back end shows while the front end
 * kept listing the event at its old time.
 */
#[AsCommand(name: 'contao:event:update', description: 'Update a calendar event')]
class EventUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return CalendarEventsModel::class; }
    protected function entityName(): string { return 'Event'; }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('startDate', null, InputOption::VALUE_REQUIRED, 'New start date (Y-m-d)');
        $this->addOption('endDate',   null, InputOption::VALUE_REQUIRED, 'New end date (Y-m-d); --set endDate= removes it');
        $this->addOption('startTime', null, InputOption::VALUE_REQUIRED, 'New start time (H:i) — makes it an event with a time');
        $this->addOption('endTime',   null, InputOption::VALUE_REQUIRED, 'New end time (H:i)');
    }

    protected function doExecute(array $fields): int
    {
        try {
            $fields = $this->dateOptionFields() + $fields;
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        return parent::doExecute($fields);
    }

    /**
     * The four date and time options as the columns they stand for.
     *
     * @return array<string, int|string>
     */
    private function dateOptionFields(): array
    {
        $own = [];

        foreach (['startDate', 'endDate'] as $name) {
            $value = (string) ($this->input->getOption($name) ?? '');

            if ('' !== $value) {
                $own[$name] = EventTimes::day($value);
            }
        }

        foreach (['startTime', 'endTime'] as $name) {
            $value = (string) ($this->input->getOption($name) ?? '');

            if ('' !== $value) {
                $own[$name]     = EventTimes::timeOfDay($value);
                $own['addTime'] = '1';
            }
        }

        return $own;
    }

    protected function preProcessFields(array $fields, object $record): array
    {
        if ([] === array_intersect(array_keys($fields), EventTimes::INPUTS)) {
            return $fields;
        }

        // A value that is not a number yet is left for convertFields() to refuse
        // with its own message; computing with it would only hide the typo.
        foreach (['startDate', 'endDate', 'startTime', 'endTime'] as $name) {
            if (isset($fields[$name]) && '' !== $fields[$name] && !is_numeric($fields[$name])) {
                return $fields;
            }
        }

        $stored = method_exists($record, 'row') ? $record->row() : [];

        if (null !== $refusal = EventTimes::refusal($fields, $stored)) {
            throw new \InvalidArgumentException($refusal . ' Nothing was written.');
        }

        return EventTimes::adjust(array_merge($stored, $fields)) + $fields;
    }
}
