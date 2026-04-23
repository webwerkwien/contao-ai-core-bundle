<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:event:read', description: 'Read a Contao calendar event as JSON')]
class EventReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Event ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id    = (int) $this->input->getArgument('id');
        $event = CalendarEventsModel::findById($id);

        if ($event === null) {
            return $this->outputError("Event not found: $id");
        }

        $this->outputRecord($event->row());
        return Command::SUCCESS;
    }
}
