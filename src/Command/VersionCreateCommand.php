<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

#[AsCommand(name: 'contao:version:create', description: 'Manually create a version snapshot for a record')]
class VersionCreateCommand extends Command
{
    use JsonErrorBoundary;

    use OperatorOptionTrait;

    private ?SystemLog $systemLog = null;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
    ) {
        parent::__construct();
    }

    #[Required]
    public function setSystemLog(SystemLog $systemLog): void
    {
        $this->systemLog = $systemLog;
    }

    protected function configure(): void
    {
        $this
            ->addOption('table',    null, InputOption::VALUE_REQUIRED, 'Table name, e.g. tl_content')
            ->addOption('id',       null, InputOption::VALUE_REQUIRED, 'Record ID');

        $this->addOperatorOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, fn (): int => $this->doExecute($input, $output));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $table    = $input->getOption('table');
        $id       = (int) $input->getOption('id');
        $operator = (string) $input->getOption('operator');

        if (!$table || !$id) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--table and --id are required'], JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        // The DCA decides whether a table keeps versions, so it is loaded first.
        $this->framework->initialize();

        if (null !== ($refusal = $this->versionManager->refusal($table))) {
            $output->writeln(json_encode(['status' => 'error', 'message' => $refusal], JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        $before = $this->versionManager->latestVersion($table, $id);
        $this->versionManager->createVersion($table, $id, '' !== $operator ? $operator : null);
        $version = $this->versionManager->latestVersion($table, $id);

        // Contao's create() returns silently for a record that does not exist or was
        // never saved (tstamp 0). Until v1.4.0 this answered "ok" either way.
        if ($version <= $before) {
            $output->writeln(json_encode([
                'status'  => 'error',
                'message' => "No version written: {$table}:{$id} does not exist or has never been saved.",
            ], JSON_UNESCAPED_UNICODE));

            return Command::FAILURE;
        }

        // Contao logs "Version X of record ... has been created" itself, but without a
        // user on the console; this line carries the operator.
        $this->systemLog?->write(
            sprintf('%s %s', $this->getName(), json_encode(
                ['table' => $table, 'id' => $id],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )),
            (string) $this->getName(),
            $this->resolveOperatorName($input),
        );

        $output->writeln(json_encode([
            'status'  => 'ok',
            'table'   => $table,
            'id'      => $id,
            'version' => $version,
        ], JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
