<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CalendarModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:calendar:update', description: 'Update a calendar')]
class CalendarUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return CalendarModel::class; }
    protected function entityName(): string { return 'Calendar'; }
}
