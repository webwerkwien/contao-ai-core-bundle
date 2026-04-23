<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:file:read', description: 'Read a text file from files/ and return its content as JSON')]
class FileReadCommand extends AbstractReadCommand
{
    private const MAX_BYTES = 524288; // 512 KB

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'File path relative to Contao root, e.g. files/scripts/style.css');
    }

    protected function doExecute(): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must start with files/ and must not contain ".."');
        }

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;
        if (!is_file($absPath)) {
            return $this->outputError("File not found: {$path}");
        }

        $size = filesize($absPath);
        if ($size > self::MAX_BYTES) {
            return $this->outputError("File too large ({$size} bytes). Maximum is " . self::MAX_BYTES . " bytes.");
        }

        $content = file_get_contents($absPath);
        if ($content === false) {
            return $this->outputError("Could not read file: {$path}");
        }

        // Reject binary files
        if (!mb_check_encoding($content, 'UTF-8')) {
            return $this->outputError("File is not valid UTF-8 text: {$path}");
        }

        $this->outputRecord(['path' => $path, 'content' => $content, 'size' => $size]);
        return Command::SUCCESS;
    }
}
