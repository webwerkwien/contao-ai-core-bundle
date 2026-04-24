<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Write a Twig template to the correct path under templates/.
 *
 * Path is calculated from --mode, --base, and optional --name so that agents
 * can never place templates in the wrong location:
 *
 *  override: templates/<base>.html.twig
 *  partial:  templates/<dir>/_<basename>.html.twig  (underscore auto-prepended)
 *  variant:  templates/<base>/<name>.html.twig
 */
#[AsCommand(name: 'contao:template:write', description: 'Write a Twig template to the correct path under templates/')]
class TemplateWriteCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode',   null, InputOption::VALUE_REQUIRED, 'override, partial, or variant')
            ->addOption('base',   null, InputOption::VALUE_REQUIRED, 'Base template path without extension, e.g. content_element/text')
            ->addOption('name',   null, InputOption::VALUE_OPTIONAL, 'Variant name (required for mode=variant)', '')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Absolute path of temp file on the server to read content from');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode   = $input->getOption('mode');
        $base   = $input->getOption('base');
        $name   = $input->getOption('name') ?: '';
        $source = $input->getOption('source');

        if (!$mode || !$base || !$source) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--mode, --base and --source are required']));
            return self::FAILURE;
        }

        if (!in_array($mode, ['override', 'partial', 'variant'], true)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "--mode must be one of: override, partial, variant"]));
            return self::FAILURE;
        }

        if ($mode === 'variant' && $name === '') {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--name is required for mode=variant']));
            return self::FAILURE;
        }

        // Guard against path traversal in --base and --name
        if (str_contains($base, '..') || str_contains($name, '..') || str_contains($base, '/..') || $name !== ltrim($name, '/')) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Invalid --base or --name value']));
            return self::FAILURE;
        }

        $relPath = $this->buildRelPath($mode, $base, $name);
        $absPath = rtrim($this->projectDir, '/') . '/templates/' . $relPath;

        if (!is_file($source)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Source file not found']));
            return self::FAILURE;
        }

        $content = file_get_contents($source);
        if ($content === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Cannot read source file']));
            return self::FAILURE;
        }

        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (file_put_contents($absPath, $content) === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Cannot write template']));
            return self::FAILURE;
        }

        $output->writeln(json_encode([
            'status' => 'ok',
            'path'   => 'templates/' . $relPath,
            'mode'   => $mode,
            'bytes'  => strlen($content),
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function buildRelPath(string $mode, string $base, string $name): string
    {
        return match ($mode) {
            'override' => $base . '.html.twig',
            'partial'  => dirname($base) . '/_' . basename($base) . '.html.twig',
            'variant'  => $base . '/' . $name . '.html.twig',
        };
    }
}
