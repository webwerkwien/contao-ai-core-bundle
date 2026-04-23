<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:version:create', description: 'Manually create a version snapshot for a record')]
class VersionCreateCommand extends Command
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

        $versions = new Versions($table, $id);
        $versions->initialize();
        $versions->create();

        // Fetch the new version number
        $newVersion = (int) $this->connection->fetchOne(
            'SELECT MAX(version) FROM tl_version WHERE fromTable = ? AND pid = ?',
            [$table, $id]
        );

        $output->writeln(json_encode([
            'status'  => 'ok',
            'table'   => $table,
            'id'      => $id,
            'version' => $newVersion,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
