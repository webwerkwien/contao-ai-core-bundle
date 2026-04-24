<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:search:query', description: 'Search the Contao fulltext search index')]
class SearchQueryCommand extends AbstractReadCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::REQUIRED, 'Search query');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max results (default: 20)', 20);
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        $query = $this->input->getArgument('query');
        $limit = (int) $this->input->getOption('limit');

        $results = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.url, s.title, s.language, s.tstamp
             FROM tl_search s
             INNER JOIN tl_search_index i ON i.pid = s.id
             WHERE i.word LIKE :query
             GROUP BY s.id
             ORDER BY s.tstamp DESC
             LIMIT :limit',
            ['query' => '%' . $query . '%', 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        $this->outputRecord(['query' => $query, 'count' => count($results), 'results' => $results]);
        return Command::SUCCESS;
    }
}
