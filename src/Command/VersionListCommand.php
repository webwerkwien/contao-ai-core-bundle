<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:version:list', description: 'List version history for a record')]
class VersionListCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Table name, e.g. tl_content')
            ->addOption('id',    null, InputOption::VALUE_REQUIRED, 'Record ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = $input->getOption('table');
        $id    = (int) $input->getOption('id');

        if (!$table || !$id) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--table and --id are required']));
            return self::FAILURE;
        }

        $this->framework->initialize();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, version, tstamp, username, active FROM tl_version WHERE fromTable = ? AND pid = ? ORDER BY version DESC',
            [$table, $id]
        );

        $versions = array_map(static function (array $r): array {
            return [
                'version'  => (int) $r['version'],
                'tstamp'   => (int) $r['tstamp'],
                'username' => $r['username'],
                'active'   => (bool) $r['active'],
            ];
        }, $rows);

        $output->writeln(json_encode([
            'status'   => 'ok',
            'table'    => $table,
            'id'       => $id,
            'versions' => $versions,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return self::SUCCESS;
    }
}
