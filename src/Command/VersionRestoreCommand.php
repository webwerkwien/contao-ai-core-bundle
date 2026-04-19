<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:version:restore', description: 'Restore a record to a specific version')]
class VersionRestoreCommand extends Command
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('table',   null, InputOption::VALUE_REQUIRED, 'Table name, e.g. tl_content')
            ->addOption('id',      null, InputOption::VALUE_REQUIRED, 'Record ID')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Version number to restore');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table   = $input->getOption('table');
        $id      = (int) $input->getOption('id');
        $version = (int) $input->getOption('version');

        if (!$table || !$id || !$version) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--table, --id and --version are required']));
            return self::FAILURE;
        }

        $this->framework->initialize();

        $versions = new Versions($table, $id);
        $versions->restore($version);

        $output->writeln(json_encode([
            'status'            => 'ok',
            'table'             => $table,
            'id'                => $id,
            'restored_version'  => $version,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
