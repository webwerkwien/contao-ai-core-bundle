<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:version:restore', description: 'Restore a record to a specific version')]
class VersionRestoreCommand extends Command
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
            ->addOption('table',   null, InputOption::VALUE_REQUIRED, 'Table name, e.g. tl_content')
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Record ID')
            ->addOption('ver', null, InputOption::VALUE_REQUIRED, 'Version number to restore');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table   = $input->getOption('table');
        $id      = (int) $input->getOption('id');
        $version = (int) $input->getOption('ver');

        if (!$table || !$id || !$version) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--table, --id and --ver are required']));
            return self::FAILURE;
        }

        $this->framework->initialize();

        $versionRow = $this->connection->fetchAssociative(
            'SELECT data FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?',
            [$table, $id, $version]
        );

        if ($versionRow === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Version {$version} not found for {$table}:{$id}"]));
            return self::FAILURE;
        }

        $data = @unserialize($versionRow['data']);
        if (!is_array($data)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Could not deserialize version data']));
            return self::FAILURE;
        }

        // Remove primary key from update data to avoid duplicate-key issues
        unset($data['id']);

        $this->connection->update("`{$table}`", $data, ['id' => $id]);

        // Mark this version as active, deactivate others
        $this->connection->executeStatement(
            'UPDATE tl_version SET active = 0 WHERE fromTable = ? AND pid = ?',
            [$table, $id]
        );
        $this->connection->executeStatement(
            'UPDATE tl_version SET active = 1 WHERE fromTable = ? AND pid = ? AND version = ?',
            [$table, $id, $version]
        );

        $output->writeln(json_encode([
            'status'           => 'ok',
            'table'            => $table,
            'id'               => $id,
            'restored_version' => $version,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
