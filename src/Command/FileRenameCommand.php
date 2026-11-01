<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Automator;
use Contao\System;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Rename a file or folder below files/ — it stays in its folder and keeps its UUID.
 *
 * The counterpart of FileMoveCommand: the same `Files::rename()` and DBAFS sync, so every
 * element that points at the file by UUID keeps working (see AbstractFileRelocateCommand).
 * Deleting and writing under the new name would give a new UUID.
 *
 * `--name` is the complete new name, extension included, and held to the rules of the back
 * end's name field (`DC_Folder::save()` and `tl_files::checkFilename()`, 5.3 to 6.0): a
 * file keeps its extension exactly as written, case included — a rename changes the name,
 * not the file type — and no name starts or ends with a dot. So a rename cannot turn
 * `a.txt` into `a.php`, `x.PHP` into `x.php` or anything into `.htaccess`, and needs no
 * check against `uploadTypes`: the file type is the one it was accepted with.
 */
#[AsCommand(name: 'contao:file:rename', description: 'Rename a file or folder below files/, keeping its UUID and a file\'s extension')]
class FileRenameCommand extends AbstractFileRelocateCommand
{
    protected function pastTense(): string
    {
        return 'renamed';
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder to rename, e.g. files/media/logo.svg')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The new name — a name, not a path — e.g. logo-2026.svg. A file keeps its extension');
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
        if (1 === preg_match('/[\x00-\x1F\x7F"]/', $name)) {
            return '--name must not contain control characters or ".';
        }
        // The back end refuses both: a leading dot hides the file, and its file manager
        // would not show it; a trailing dot is dropped by some file systems.
        if (str_starts_with($name, '.')) {
            return '--name must not start with a dot — the back end refuses such names too.';
        }
        if (str_ends_with($name, '.')) {
            return '--name must not end with a dot.';
        }
        if (\strlen($name) > 255) {
            return '--name is longer than 255 bytes.';
        }

        return null;
    }

    /**
     * Why a file named $source cannot be renamed to $name, or null when it can: as in the
     * back end, the extension stays exactly as it is — none added, none removed, no change
     * of case. A folder may be named anything that is a name.
     */
    public static function extensionRefusal(string $source, string $name, bool $isFolder): ?string
    {
        if ($isFolder) {
            return null;
        }

        $extension = Path::getExtension($source);

        if (Path::getExtension($name) === $extension) {
            return null;
        }

        return '' === $extension
            ? "{$source} has no extension, and a rename does not add one — it changes the name, not the file type, as in the back end."
            : "{$source} keeps its extension: give a name ending in .{$extension}. A rename changes the name, not the file type, as in the back end.";
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
        $isFolder = is_dir($this->projectDir . '/' . $source) && !is_link($this->projectDir . '/' . $source);
        $refusal  = self::extensionRefusal($source, basename($destination), $isFolder);

        return null === $refusal ? null : $refusal . ' Nothing was renamed.';
    }

    /**
     * What the back end's rename does and a move does not (`DC_Folder::save()` for the
     * name field, 5.3 to 6.0): the thumbnails of an image under its old name are removed
     * from the image target dir (Contao #6641), and renaming a style sheet or script
     * empties the script cache, which holds them by path.
     */
    protected function afterRename(string $source, string $destination, bool $isFolder): array
    {
        if ($isFolder) {
            return [];
        }

        $container = System::getContainer();
        $extension = Path::getExtension($source, true);
        $data      = [];

        if (\in_array($extension, (array) $container->getParameter('contao.image.valid_extensions'), true)) {
            // The old name is matched literally ([, * and ? are glob syntax and may be part
            // of a file name), and only files are removed, as Files::delete() does.
            $stem       = strtr(Path::getFilenameWithoutExtension($source), ['[' => '[[]', '*' => '[*]', '?' => '[?]']);
            $pattern    = $container->getParameter('contao.image.target_dir') . '/*/' . $stem . '-*.' . Path::getExtension($source);
            $thumbnails = array_values(array_filter(glob($pattern) ?: [], is_file(...)));

            (new Filesystem())->remove($thumbnails);

            $data['thumbnailsRemoved'] = \count($thumbnails);
        }

        if (\in_array($extension, ['css', 'scss', 'less', 'js'], true)) {
            (new Automator())->purgeScriptCache();
            $data['scriptCachePurged'] = true;
        }

        return $data;
    }
}
