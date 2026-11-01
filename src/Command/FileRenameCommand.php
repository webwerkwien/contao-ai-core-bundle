<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rename a file or folder below files/ — it stays in its folder and keeps its UUID.
 *
 * The counterpart of FileMoveCommand: the same `Files::rename()` and DBAFS sync, so every
 * element that points at the file by UUID keeps working (see AbstractFileRelocateCommand).
 * Deleting and writing under the new name would give a new UUID.
 *
 * `--name` is the complete new name, extension included. Renaming must not open a way
 * around what a write refuses: a file that gets a *different* extension is held against
 * `uploadTypes` and against the extensions the web server runs (UploadPolicy), as an
 * upload with that name would be. Keeping the extension is always fine, so an existing
 * file stays renamable whatever `uploadTypes` says today.
 */
#[AsCommand(name: 'contao:file:rename', description: 'Rename a file or folder below files/, keeping its UUID')]
class FileRenameCommand extends AbstractFileRelocateCommand
{
    use UploadPolicy;

    protected function pastTense(): string
    {
        return 'renamed';
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder to rename, e.g. files/media/logo.svg')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The new name — a name, not a path — e.g. logo-2026.svg. Extension included');
    }

    /**
     * Why $name cannot be a file or folder name, or null when it can.
     */
    public static function nameRefusal(string $name): ?string
    {
        if ('' === $name || '.' === $name || '..' === $name) {
            return '--name must be a file or folder name.';
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return '--name is a name, not a path — it must not contain / or \\. To put the file into another folder use contao:file:move.';
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return '--name must not contain control characters.';
        }
        if (\strlen($name) > 255) {
            return '--name is longer than 255 bytes.';
        }

        return null;
    }

    protected function doExecute(array $fields): int
    {
        $source = (string) $this->input->getOption('path');
        $name   = (string) $this->input->getOption('name');

        if ('' === $source) {
            return $this->outputError('--path is required');
        }
        if ('' === $name) {
            return $this->outputError('--name is required: the new name, e.g. logo-2026.svg.');
        }

        $source = FileDeleteCommand::canonicalPath($source);

        if (null === $source) {
            return $this->outputError('--path must be below files/ and must not contain ".." — files/ itself cannot be renamed. Nothing was renamed.');
        }

        $refusal = self::nameRefusal($name);

        if (null !== $refusal) {
            return $this->outputError($refusal . ' Nothing was renamed.');
        }

        if (!is_link($this->projectDir . '/' . $source) && !file_exists($this->projectDir . '/' . $source)) {
            return $this->outputError("File or folder not found: {$source}. Nothing was renamed.");
        }
        if (basename($source) === $name) {
            return $this->outputError("{$source} already has this name. Nothing was renamed.");
        }

        return $this->relocate($source, \dirname($source) . '/' . $name, ['name' => $name]);
    }

    protected function refuseDestination(string $source, string $destination): ?string
    {
        if (is_dir($this->projectDir . '/' . $source) && !is_link($this->projectDir . '/' . $source)) {
            return null;
        }

        return $this->extensionRefusal($source, $destination, (string) Config::get('uploadTypes'));
    }

    /**
     * The pure half of the rule: a file that gets another extension, or becomes `.user.ini`,
     * must be acceptable as an upload under its new name.
     */
    public function extensionRefusal(string $source, string $destination, string $uploadTypes): ?string
    {
        $becomesUserIni = '.user.ini' === strtolower(basename($destination)) && '.user.ini' !== strtolower(basename($source));

        if (!$becomesUserIni && $this->extensionOf($source) === $this->extensionOf($destination)) {
            return null;
        }

        $violation = $this->uploadViolation($destination, 0, $uploadTypes, \PHP_INT_MAX);

        // The upload check speaks of writing; here a file gets a new name.
        return null === $violation ? null : str_replace(
            ['it is not written into files/', 'does not accept it. Nothing was written.', 'Nothing was written.'],
            ['it is not accepted into files/ under that name', 'does not accept it. Nothing was renamed.', 'Nothing was renamed.'],
            $violation,
        );
    }
}
