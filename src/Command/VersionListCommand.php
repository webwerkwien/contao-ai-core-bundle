<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

#[AsCommand(name: 'contao:version:list', description: 'List version history for a record')]
class VersionListCommand extends Command
{
    use JsonErrorBoundary;

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
        return $this->guarded($output, fn (): int => $this->doExecute($input, $output));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $table = $input->getOption('table');
        $id    = (int) $input->getOption('id');

        if (!$table || !$id) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--table and --id are required']));
            return self::FAILURE;
        }

        $this->framework->initialize();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, version, tstamp, username, active, description FROM tl_version WHERE fromTable = ? AND pid = ? ORDER BY version DESC',
            [$table, $id]
        );

        // The current record begins at its newest `created` version (v0.16.0). Older
        // versions belong to an earlier record that had this ID; `version restore`
        // refuses them. Without a marker nothing is known and nothing is flagged.
        $creation = null;
        foreach ($rows as $r) {
            if (VersionManager::CREATED === ($r['description'] ?? null)) {
                $creation = max($creation ?? 0, (int) $r['version']);
            }
        }

        $versions = array_map(static function (array $r) use ($creation): array {
            return [
                'version'         => (int) $r['version'],
                'tstamp'          => (int) $r['tstamp'],
                'username'        => $r['username'],
                'active'          => (bool) $r['active'],
                'before_creation' => null !== $creation && (int) $r['version'] < $creation,
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
