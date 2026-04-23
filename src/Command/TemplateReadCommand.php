<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:template:read', description: 'Read a template file and return its content as JSON')]
class TemplateReadCommand extends Command
{
    private const MAX_BYTES = 524288; // 512 KB

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Template path relative to Contao root, e.g. templates/content_element/text.html.twig');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('path');
        if (!$path) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--path is required']));
            return self::FAILURE;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'templates/')) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Path must start with templates/ and must not contain ".."']));
            return self::FAILURE;
        }

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;
        if (!is_file($absPath)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Template not found: {$path}"]));
            return self::FAILURE;
        }

        $size = filesize($absPath);
        if ($size > self::MAX_BYTES) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "File too large ({$size} bytes). Maximum is " . self::MAX_BYTES . " bytes."]));
            return self::FAILURE;
        }

        $content = file_get_contents($absPath);
        $output->writeln(json_encode(['status' => 'ok', 'path' => $path, 'content' => $content, 'size' => $size], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
