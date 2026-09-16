<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Dbafs;
use Contao\Files;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Files\FileUsageFinder;

/**
 * Delete a file or folder below files/ — as the back end does, and not while it is used.
 *
 * Until v0.18.0 there was no way to delete a file through the bundle (Nr. 46 of the ConpAI
 * 1.0 acceptance test, 2026-09-16). See FileDeleteCommandTest.
 *
 * Mirrors `DC_Folder::delete()` (identical in 5.7.13 and 6.0.0): the resource first —
 * `Files::rrdir()` plus the web dir symlink for a folder, `Files::delete()` for a file —
 * then `Dbafs::deleteResource()`, then the files log channel. Before that, FileUsageFinder:
 * a file or folder that is still referenced is refused unless `--force` is given, because
 * Contao checks nothing and there is no `tl_undo` for files.
 */
#[AsCommand(name: 'contao:file:delete', description: 'Delete a file or folder below files/ with its DBAFS records — refused while it is still used, unless --force')]
class FileDeleteCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function systemLogAction(): string
    {
        return ContaoContext::FILES;
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder path relative to Contao root, e.g. files/conpai/bild.png')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete even when the file is still used; the usages are listed in the answer');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = trim(str_replace('\\', '/', (string) $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must be a file or folder below files/ and must not contain ".." — files/ itself cannot be deleted.');
        }

        $jailRoot = realpath($this->projectDir . '/files');
        $realPath = realpath($this->projectDir . '/' . $path);

        if (false === $realPath) {
            return $this->outputError("File or folder not found: {$path}. Nothing was deleted.");
        }
        if (false === $jailRoot || !str_starts_with($realPath, $jailRoot . DIRECTORY_SEPARATOR)) {
            return $this->outputError('Path must be a file or folder below files/ — it resolves outside of it.');
        }

        $this->framework->initialize();

        $isFolder  = is_dir($realPath);
        $resources = $this->resources($path);
        $usages    = (new FileUsageFinder($this->connection))->find($resources);
        $force     = (bool) $this->input->getOption('force');

        if ([] !== $usages && !$force) {
            $this->output->writeln(json_encode([
                'status'  => 'error',
                'message' => \sprintf(
                    '%s is still used in %d place(s)%s. Nothing was deleted. Remove the references first, or pass --force — a deleted file cannot be restored.',
                    $path,
                    \count($usages),
                    \count($usages) >= 50 ? ' (at least)' : '',
                ),
                'usages'  => $usages,
                'code'    => 1,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return Command::FAILURE;
        }

        // DC_Folder::delete(): the resource first, then the database.
        $files = Files::getInstance();

        if ($isFolder) {
            $files->rrdir($path);

            $webDir = StringUtil::stripRootDir((string) System::getContainer()->getParameter('contao.web_dir'));

            if (is_link($this->projectDir . '/' . $webDir . '/' . $path)) {
                $files->delete($webDir . '/' . $path);
            }
        } else {
            $files->delete($path);
        }

        clearstatcache();

        if (file_exists($realPath)) {
            return $this->outputError("{$path} could not be deleted from the file system. The database was left unchanged.");
        }

        Dbafs::deleteResource($path);

        System::getContainer()->get('monolog.logger.contao.files')->info('File or folder "' . $path . '" has been deleted');

        $data = [
            'path'     => $path,
            'type'     => $isFolder ? 'folder' : 'file',
            'deleted'  => true,
            'records'  => \count($resources),
            'undoable' => false,
        ];

        if ([] !== $usages) {
            $data['usages'] = $usages;
        }

        $this->outputSuccess($data);

        return Command::SUCCESS;
    }

    /**
     * The tl_files records of the resource and everything below it.
     *
     * @return list<array{path: string, uuid: string|null}>
     */
    private function resources(string $path): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT path, uuid FROM tl_files WHERE path = ? OR path LIKE ?',
            [$path, addcslashes($path, '%_\\') . '/%'],
        );

        $resources = array_map(
            static fn (array $row): array => ['path' => (string) $row['path'], 'uuid' => null === $row['uuid'] ? null : (string) $row['uuid']],
            $rows,
        );

        // A file the DBAFS does not know can still be referenced by its path.
        if ([] === $resources) {
            $resources[] = ['path' => $path, 'uuid' => null];
        }

        return $resources;
    }
}
