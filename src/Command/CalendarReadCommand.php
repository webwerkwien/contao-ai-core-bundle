<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CalendarModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:calendar:read', description: 'Read a calendar record as JSON')]
class CalendarReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return CalendarModel::class; }
    protected function entityName(): string { return 'Calendar'; }
}
