<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CalendarEventsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:event:delete', description: 'Delete a calendar event')]
class EventDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return CalendarEventsModel::class; }
    protected function entityName(): string { return 'Event'; }
}
