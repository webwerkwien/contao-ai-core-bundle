<?php declare(strict_types=1);

declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:template:read', description: 'Read a template file and return its content as JSON')]
class TemplateReadCommand extends AbstractReadCommand
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

    protected function doExecute(): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'templates/')) {
            return $this->outputError('Path must start with templates/ and must not contain ".."');
        }

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;
        if (!is_file($absPath)) {
            return $this->outputError("Template not found: {$path}");
        }

        // Realpath jail: reject symlinks pointing outside projectDir
        $real = realpath($absPath);
        if ($real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, realpath($this->projectDir) . DIRECTORY_SEPARATOR)) {
            return $this->outputError('Access denied: path resolves outside allowed directory');
        }
        $absPath = $real;

        $size = filesize($absPath);
        if ($size === false) {
            return $this->outputError("Cannot determine file size: {$path}");
        }
        if ($size > self::MAX_BYTES) {
            return $this->outputError("File too large ({$size} bytes). Maximum is " . self::MAX_BYTES . " bytes.");
        }

        $content = file_get_contents($absPath);
        if ($content === false) {
            return $this->outputError('Cannot read template file');
        }
        $this->outputRecord(['path' => $path, 'content' => $content, 'size' => $size]);

        return self::SUCCESS;
    }
}
