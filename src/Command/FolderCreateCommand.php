<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:folder:create', description: 'Create a folder in the Contao file system')]
class FolderCreateCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Folder path relative to Contao root, e.g. files/images/gallery');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        // Normalize and guard against path traversal
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must start with files/ and must not contain ".."  (outside-files-root)');
        }

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;

        // Realpath jail: walk up to deepest existing ancestor to catch symlinks
        $jailRoot   = realpath($this->projectDir) . DIRECTORY_SEPARATOR;
        $jailCheck  = $absPath;
        while (!file_exists($jailCheck) && $jailCheck !== dirname($jailCheck)) {
            $jailCheck = dirname($jailCheck);
        }
        $realAncestor = realpath($jailCheck);
        if ($realAncestor === false || !str_starts_with($realAncestor . DIRECTORY_SEPARATOR, $jailRoot)) {
            return $this->outputError('Access denied: path resolves outside allowed directory');
        }

        $existed = is_dir($absPath);
        if (!$existed && !mkdir($absPath, 0775, true)) {
            return $this->outputError("Could not create directory: {$path}");
        }

        $this->framework->initialize();

        $file = FilesModel::findByPath($path);
        if ($file === null) {
            $file         = new FilesModel();
            $file->pid    = $this->resolveParentUuid(dirname($path));
            $file->tstamp = time();
            $file->type   = 'folder';
            $file->path   = $path;
            $file->name   = basename($path);
            $file->hash   = '';
        }
        $file->tstamp = time();
        $file->save();

        $this->outputSuccess(['path' => $path, 'created' => !$existed]);
        return Command::SUCCESS;
    }

    private function resolveParentUuid(string $parentPath): string
    {
        if ($parentPath === '.' || $parentPath === '') {
            return '';
        }
        $parent = FilesModel::findByPath($parentPath);
        return $parent?->uuid ?? '';
    }
}
