<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:event:update', description: 'Update a calendar event')]
class EventUpdateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'Event ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id    = (int) $this->input->getArgument('id');
        $event = CalendarEventsModel::findById($id);

        if ($event === null) {
            return $this->outputError("Event not found: $id");
        }
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        foreach ($fields as $key => $value) {
            $event->$key = $value;
        }
        $event->tstamp = time();
        $event->save();

        $this->outputSuccess(['id' => $id, 'updated' => array_keys($fields)]);
        return 0;
    }
}
