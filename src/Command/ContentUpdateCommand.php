<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:content:update', description: 'Update a content element')]
class ContentUpdateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'Content element ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id = (int) $this->input->getArgument('id');
        $el = ContentModel::findById($id);

        if ($el === null) {
            return $this->outputError("Content element not found: $id");
        }
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        foreach ($fields as $key => $value) {
            $el->$key = $value;
        }
        $this->createVersion('tl_content', $id);
        $el->tstamp = time();
        $el->save();

        $this->outputSuccess(['id' => $id, 'updated' => array_keys($fields)]);
        return 0;
    }
}
