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
 *
 * Files the server runs or evaluates (PHP, legacy `.html5` templates, Twig, `.htaccess`)
 * are listed by name in `code_files`, new or overwritten, and the import runs only with
 * `--allow-code` as well (v1.14.0): an archive from elsewhere can carry a template that
 * runs on every page.
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
        $this->addOption('allow-code', null, InputOption::VALUE_NONE, 'Also import when the archive holds files the server runs (see code_files in the preview)');
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

            if ([] !== $preview['code_files'] && !$input->getOption('allow-code')) {
                throw new \InvalidArgumentException(\sprintf(
                    'The archive holds %d file(s) the server runs or evaluates: %s. Check them, then import with --confirm --allow-code. Nothing was imported.',
                    \count($preview['code_files']),
                    implode(', ', $preview['code_files']),
                ));
            }

            $result = $this->archive->import($file);

            $this->systemLog?->write(
                \sprintf('%s %s', $this->getName(), $this->json(['file' => basename($file), 'themes' => $preview['themes'], 'theme_ids' => $result['theme_ids']])),
                (string) $this->getName(),
                $this->resolveOperatorName($input),
            );

            // No new theme: the bundle's error shape, with Contao's own messages as the why.
            $failed = [] === $result['theme_ids'];
            $why = implode(' ', array_column($result['messages'], 'text'));
            $output->writeln($this->json(['status' => $failed ? 'error' : 'ok'] + ($failed ? [
                'message' => 'Contao imported no theme.' . ('' !== $why ? ' ' . $why : ''),
                'code'    => 1,
            ] : []) + $result + [
                'overwritten' => $preview['overwrites'],
                'not_imported' => $preview['example_websites'],
                // Layout slots Contao's import left empty: their theme elements are not in a .cto.
                'lost_theme_elements' => $preview['lost_theme_elements'],
            ]));

            return $failed ? Command::FAILURE : Command::SUCCESS;
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
