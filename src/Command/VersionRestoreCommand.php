<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoCliBridgeBundle\Service\VersionManager;

#[AsCommand(name: 'contao:version:restore', description: 'Restore a record to a specific version')]
class VersionRestoreCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Table name, e.g. tl_content')
            ->addOption('id',    null, InputOption::VALUE_REQUIRED, 'Record ID')
            ->addOption('ver',   null, InputOption::VALUE_REQUIRED, 'Version number to restore');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table   = $input->getOption('table');
        $id      = (int) $input->getOption('id');
        $version = (int) $input->getOption('ver');

        if (!$table || !$id || !$version) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--table, --id and --ver are required'], JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        if (!$this->versionManager->isAllowedTable($table)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Table not allowed: {$table}"], JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        $this->framework->initialize();

        $data = $this->versionManager->loadVersionData($table, $id, $version);
        if ($data === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Version {$version} not found or corrupt for {$table}:{$id}"], JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        unset($data['id']);
        $this->connection->update('`' . $table . '`', $data, ['id' => $id]);
        $this->versionManager->markActiveVersion($table, $id, $version);

        $output->writeln(json_encode([
            'status'           => 'ok',
            'table'            => $table,
            'id'               => $id,
            'restored_version' => $version,
        ], JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
