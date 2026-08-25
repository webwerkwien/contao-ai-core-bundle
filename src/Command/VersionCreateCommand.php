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
        $table    = $input->getOption('table');
        $id       = (int) $input->getOption('id');
        $operator = (string) $input->getOption('operator');

        if (!$table || !$id) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--table and --id are required'], JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        if (!$this->versionManager->isAllowedTable($table)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Table not allowed: {$table}"], JSON_UNESCAPED_UNICODE));
            return Command::FAILURE;
        }

        $this->framework->initialize();
        $this->versionManager->createVersion($table, $id, '' !== $operator ? $operator : null);

        // Contao logs "Version X of record ... has been created" whenever its own
        // Versions class runs. VersionManager writes tl_version directly, so that
        // line has to come from here.
        $this->systemLog?->write(
            sprintf('%s %s', $this->getName(), json_encode(
                ['table' => $table, 'id' => $id],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )),
            (string) $this->getName(),
            $this->resolveOperatorName($input),
        );

        $output->writeln(json_encode([
            'status' => 'ok',
            'table'  => $table,
            'id'     => $id,
        ], JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
