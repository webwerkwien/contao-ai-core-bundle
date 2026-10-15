<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\Theme\ThemeArchive;

/**
 * `contao:theme:export <id>` — the theme manager's `.cto`, written to system/tmp (v1.9.0).
 * contao-ai-cli downloads it and removes it; see ThemeArchive.
 */
#[AsCommand(name: 'contao:theme:export', description: "Export a theme as Contao's .cto archive into system/tmp")]
class ThemeExportCommand extends Command
{
    use JsonErrorBoundary;

    public function __construct(private readonly ThemeArchive $archive)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'tl_theme ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, function () use ($input, $output): int {
            $result = $this->archive->export((int) $input->getArgument('id'));

            $output->writeln(json_encode(['status' => 'ok'] + $result + [
                // Contao's archive format; theme elements (5.6+) are not part of it.
                'note' => "Contao's own theme export: theme, image sizes, modules, layouts and the theme's files and templates. Theme elements are not part of a .cto.",
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return Command::SUCCESS;
        });
    }
}
