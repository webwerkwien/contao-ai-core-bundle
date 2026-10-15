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
use Webwerkwien\ContaoAiCoreBundle\Service\Theme\ThemeArchive;

/**
 * `contao:theme:import <file> [--confirm]` — Contao's theme import on the console (v1.9.0).
 *
 * Without --confirm it only says what would happen: the theme, the rows per table, the
 * files in files/ and templates/ the archive would overwrite, fields this database lacks,
 * example websites inside (not imported here). An import has no undo — files are
 * overwritten and records created — so it runs only when asked twice.
 */
#[AsCommand(name: 'contao:theme:import', description: "Import a theme from Contao's .cto archive (preview without --confirm)")]
class ThemeImportCommand extends Command
{
    use JsonErrorBoundary;

    use OperatorOptionTrait;

    private ?SystemLog $systemLog = null;

    public function __construct(private readonly ThemeArchive $archive)
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
        $this->addArgument('file', InputArgument::REQUIRED, 'The .cto/.zip, relative to the project root, in ' . ThemeArchive::TMP_DIR . '/');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Import; without it the command only previews');
        $this->addOperatorOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, function () use ($input, $output): int {
            $file    = (string) $input->getArgument('file');
            $preview = $this->archive->analyse($file);

            if (!$input->getOption('confirm')) {
                $output->writeln($this->json(['status' => 'preview'] + $preview));

                return Command::SUCCESS;
            }

            $result = $this->archive->import($file);

            $this->systemLog?->write(
                \sprintf('%s %s', $this->getName(), $this->json(['file' => basename($file), 'themes' => $preview['themes'], 'theme_ids' => $result['theme_ids']])),
                (string) $this->getName(),
                $this->resolveOperatorName($input),
            );

            $output->writeln($this->json(['status' => [] === $result['theme_ids'] ? 'error' : 'ok'] + $result + [
                'overwritten' => $preview['overwrites'],
                'not_imported' => $preview['example_websites'],
                // Layout slots Contao's import left empty: their theme elements are not in a .cto.
                'lost_theme_elements' => $preview['lost_theme_elements'],
            ]));

            return [] === $result['theme_ids'] ? Command::FAILURE : Command::SUCCESS;
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
