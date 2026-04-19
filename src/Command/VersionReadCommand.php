<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:version:read', description: 'Read a specific version snapshot as JSON')]
class VersionReadCommand extends Command
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
            ->addOption('ver', null, InputOption::VALUE_REQUIRED, 'Version number');
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

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?',
            [$table, $id, $version]
        );

        if ($row === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Version {$version} not found for {$table}:{$id}"]));
            return self::FAILURE;
        }

        $data = @unserialize($row['data']);
        if ($data === false) {
            $data = ['_raw' => $row['data']];
        }

        // Normalize through JSON roundtrip to handle objects (__PHP_Incomplete_Class),
        // binary strings, and other non-JSON-safe values from unserialization
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $data  = json_decode(json_encode($data, $flags), true) ?? [];

        $output->writeln(json_encode([
            'status'   => 'ok',
            'table'    => $table,
            'id'       => $id,
            'version'  => (int) $row['version'],
            'tstamp'   => (int) $row['tstamp'],
            'username' => $row['username'],
            'active'   => (bool) $row['active'],
            'data'     => $data,
        ], $flags));

        return self::SUCCESS;
    }
}
