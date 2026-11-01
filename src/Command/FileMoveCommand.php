<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Move a file or folder into another folder below files/ — as the back end's cut and paste.
 *
 * Until v0.22.0 there was no way to move a file through the bundle; deleting and writing
 * anew gives a new UUID, and every element pointing at the old one renders nothing. See
 * FileMoveCommandTest. The rules of the rename itself are AbstractFileRelocateCommand's.
 */
#[AsCommand(name: 'contao:file:move', description: 'Move a file or folder into another folder below files/, keeping its UUID — as cut and paste in the back end')]
class FileMoveCommand extends AbstractFileRelocateCommand
{
    protected function pastTense(): string
    {
        return 'moved';
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder to move, e.g. files/media/logo.svg')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Folder to move it into, e.g. files/theme/layout — files for the root. The name stays');
    }

    /**
     * A target folder as tl_files stores it; files/ itself is allowed. Null outside files/
     * or with `..`.
     */
    public static function canonicalFolder(string $path): ?string
    {
        $normalised = trim(str_replace('\\', '/', $path), '/');

        if ('files' === $normalised) {
            return 'files';
        }

        return FileDeleteCommand::canonicalPath($path);
    }

    protected function doExecute(array $fields): int
    {
        $source = (string) $this->input->getOption('path');
        $target = (string) $this->input->getOption('to');

        if ('' === $source) {
            return $this->outputError('--path is required');
        }
        if ('' === $target) {
            return $this->outputError('--to is required: the folder to move it into, files for the root.');
        }

        $source = FileDeleteCommand::canonicalPath($source);
        $target = self::canonicalFolder($target);

        if (null === $source || null === $target) {
            return $this->outputError('Both paths must be below files/ and must not contain ".." — files/ itself cannot be moved. Nothing was moved.');
        }

        if (!is_link($this->projectDir . '/' . $source) && !file_exists($this->projectDir . '/' . $source)) {
            return $this->outputError("File or folder not found: {$source}. Nothing was moved.");
        }
        if (!is_dir($this->projectDir . '/' . $target)) {
            return $this->outputError("{$target} is not a folder. --to names the folder to move into. Nothing was moved.");
        }
        if ($target === $source || str_starts_with($target . '/', $source . '/')) {
            return $this->outputError("{$source} cannot be moved into itself ({$target}). Nothing was moved.");
        }
        if (\dirname($source) === $target) {
            return $this->outputError("{$source} is already in {$target}. Nothing was moved.");
        }

        return $this->relocate($source, $target . '/' . basename($source));
    }
}
