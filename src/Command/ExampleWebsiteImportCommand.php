<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;
use Webwerkwien\ContaoAiCoreBundle\Service\Theme\ExampleWebsite;
use Webwerkwien\ContaoAiCoreBundle\Service\Theme\ThemeArchive;

/**
 * `contao:example-website:import <file> --mode=… [--admins=…] [--confirm]` (v1.9.0).
 *
 * The theme manager's example website import, which can replace or empty the whole
 * database; see ExampleWebsite. Without --confirm it previews. With it: a database
 * backup first (named in the answer), then the statements.
 */
#[AsCommand(name: 'contao:example-website:import', description: 'Import an example website (.sql) as the theme manager does (preview without --confirm)')]
class ExampleWebsiteImportCommand extends Command
{
    use JsonErrorBoundary;

    use OperatorOptionTrait;

    private ?SystemLog $systemLog = null;

    public function __construct(private readonly ExampleWebsite $exampleWebsite)
    {
        parent::__construct();
    }

    #[Required]
    public function setSystemLog(SystemLog $systemLog): void
    {
        $this->systemLog = $systemLog;
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The .sql (or a .cto/.zip holding one), relative to the project root, in ' . ThemeArchive::TMP_DIR . '/');
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'full (every statement, DROP and CREATE included), data (empty every tl_ table, then the INSERTs) or data_no_truncate (only the INSERTs). No default.');
        $this->addOption('admins', null, InputOption::VALUE_REQUIRED, 'When administrators exist and the file brings users: both (keep them next to the imported ones) or existing (keep only them)');
        $this->addOption('entry', null, InputOption::VALUE_REQUIRED, 'Which .sql inside an archive holding several');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Import; without it the command only previews');
        $this->addOperatorOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, function () use ($input, $output): int {
            $file  = (string) $input->getArgument('file');
            $mode  = (string) $input->getOption('mode');
            $entry = $input->getOption('entry');
            $entry = \is_string($entry) && '' !== $entry ? $entry : null;

            if (!$input->getOption('confirm')) {
                $output->writeln($this->json(['status' => 'preview'] + $this->exampleWebsite->analyse($file, $mode, $entry)));

                return Command::SUCCESS;
            }

            $admins = $input->getOption('admins');
            $result = $this->exampleWebsite->import($file, $mode, \is_string($admins) ? $admins : null, $entry);

            $this->systemLog?->write(
                \sprintf('%s %s', $this->getName(), $this->json(['file' => basename($file), 'mode' => $mode, 'backup' => $result['backup'], 'admins' => $result['admins']])),
                (string) $this->getName(),
                $this->resolveOperatorName($input),
            );

            $output->writeln($this->json(['status' => 'ok', 'mode' => $mode] + $result));

            return Command::SUCCESS;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
