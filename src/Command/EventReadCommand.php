<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CalendarEventsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:event:read', description: 'Read a Contao calendar event as JSON')]
class EventReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return CalendarEventsModel::class; }
    protected function entityName(): string { return 'Event'; }
}
