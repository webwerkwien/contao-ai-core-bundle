<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:content:read', description: 'Read a Contao content element as JSON')]
class ContentReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Content element ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id      = (int) $this->input->getArgument('id');
        $content = ContentModel::findById($id);

        if ($content === null) {
            return $this->outputError("Content element not found: $id");
        }

        $row = $content->row();

        // Deserialize headline for readability
        if (isset($row['headline']) && is_string($row['headline'])) {
            $headline = StringUtil::deserialize($row['headline'], true);
            if (isset($headline['value'])) {
                $row['headline'] = $headline;
            }
        }

        $this->outputRecord($row);
        return 0;
    }
}
