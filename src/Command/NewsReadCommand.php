<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:news:read', description: 'Read a Contao news entry as JSON')]
class NewsReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'News ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $id   = (int) $this->input->getArgument('id');
        $news = NewsModel::findById($id);

        if ($news === null) {
            return $this->outputError("News entry not found: $id");
        }

        $row = $news->row();

        // Deserialize headline
        if (isset($row['headline']) && is_string($row['headline'])) {
            $headline = StringUtil::deserialize($row['headline'], true);
            if (isset($headline['value'])) {
                $row['headline'] = $headline;
            }
        }

        $this->outputRecord($row);
        return Command::SUCCESS;
    }
}
