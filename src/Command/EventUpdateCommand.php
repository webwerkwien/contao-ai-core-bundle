<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CalendarEventsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:event:update', description: 'Update a calendar event')]
class EventUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return CalendarEventsModel::class; }
    protected function entityName(): string { return 'Event'; }
    protected function tableName(): string  { return 'tl_calendar_events'; }
}
