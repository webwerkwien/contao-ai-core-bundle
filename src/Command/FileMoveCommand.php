<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Files;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Path;
use Webwerkwien\ContaoAiCoreBundle\Service\Files\FileUsageFinder;

/**
 * Move a file or folder into another folder below files/ — as the back end's cut and paste.
 *
 * Until v0.22.0 there was no way to move a file through the bundle; deleting and writing
 * anew gives a new UUID, and every element pointing at the old one renders nothing (Nr. 64
 * of the ConpAI 1.0 acceptance test, 2026-09-17). See FileMoveCommandTest.
 *
 * Mirrors `DC_Folder::cut()` of Contao 5.7/6.0: no circular move, no overwriting,
 * `Files::rename()`, then `contao.filesystem.dbafs_manager->sync($source, $destination)` —
 * which recognises the move by hash and keeps the UUIDs (5.3 calls the older
 * `Dbafs::moveResource()`; the manager and its move detection exist there too) — then the
 * symlinks for a folder, and *File or folder "…" has been moved to "…"* in the files channel.
 * Like FolderPublishCommand, the symlinks are generated with `contao.command.symlinks`
 * directly so both log lines carry the operator (Nr. 59).
 *
 * What Contao does not tell, and this answers: references that name the old **path** in a
 * text field (`pathUsages`). References by UUID — file pickers, `{{file::uuid}}` — keep
 * working; a path written into a text does not.
 */
#[AsCommand(name: 'contao:file:move', description: 'Move a file or folder into another folder below files/, keeping its UUID — as cut and paste in the back end')]
class FileMoveCommand extends AbstractWriteCommand
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
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder to move, e.g. files/conpai/bot.svg')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Folder to move it into, e.g. files/conpai-consho/layout — files for the root. The name stays');
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

        if ('.public' === basename($source)) {
            return $this->outputError("{$source} decides whether its folder is served — use file folder-publish instead of moving it. Nothing was moved.");
        }

        $destination = $target . '/' . basename($source);

        // The DBAFS manager refuses to sync a path whose first segment below files/ starts
        // with a dot ("Dot path … is not allowed"). Refused before the rename, so nothing is
        // left half-moved (review before v0.23.0; FileDeleteCommand meets the same rule).
        foreach ([$source, $destination] as $path) {
            if (str_starts_with(substr($path, \strlen('files/')), '.')) {
                return $this->outputError("{$path} starts with a dot directly below files/, and Contao's file sync refuses such paths. Nothing was moved.");
            }
        }

        if (is_link($this->projectDir . '/' . $destination) || file_exists($this->projectDir . '/' . $destination)) {
            return $this->outputError("{$destination} already exists. Nothing was moved — Contao does not overwrite on a move either.");
        }

        // No linked folder on either way: through one, the rename would act on the link's
        // target and tl_files would record paths that do not exist (as in FileDeleteCommand).
        foreach ([\dirname($source), $target] as $folder) {
            $refusal = $this->linkRefusal($folder);

            if (null !== $refusal) {
                return $this->outputError($refusal);
            }
        }

        $this->framework->initialize();

        $isFolder = is_dir($this->projectDir . '/' . $source) && !is_link($this->projectDir . '/' . $source);

        // Text that names the old path breaks with the move; UUID references do not. Searched
        // without UUIDs, the finder reports exactly the path mentions.
        $before        = $this->records($source);
        $pathResources = array_map(
            static fn (array $record): array => ['path' => $record['path'], 'uuid' => null],
            $before ?: [['path' => $source]],
        );
        $pathSearch = (new FileUsageFinder($this->connection))->find($pathResources);

        Files::getInstance()->rename($source, $destination);
        clearstatcache();

        if (!file_exists($this->projectDir . '/' . $destination) && !is_link($this->projectDir . '/' . $destination)) {
            return $this->outputError("{$source} could not be moved to {$destination}. Nothing was changed.");
        }

        $container = System::getContainer();

        try {
            $container->get('contao.filesystem.dbafs_manager')->sync($source, $destination);
        } catch (\Throwable $e) {
            $message = \sprintf(
                '%s was moved to %s, but tl_files could not be updated (%s). Run contao:filesync%s — UUIDs may change.',
                $source,
                $destination,
                $e->getMessage(),
                $isFolder ? ' and contao:symlinks' : '',
            );
            $this->logSuccess(['path' => $source, 'to' => $destination, 'error' => $message]);

            return $this->outputError($message);
        }

        if ($isFolder) {
            $webDir = Path::makeRelative((string) $container->getParameter('contao.web_dir'), (string) $container->getParameter('kernel.project_dir'));
            $status = $container->get('contao.command.symlinks')->run(new ArgvInput(['', $webDir]), new NullOutput());

            if ($status > 0) {
                $container->get('monolog.logger.contao.error')->error('The symlinks could not be regenerated', $this->logContext(ContaoContext::ERROR));
            } else {
                $container->get('monolog.logger.contao.cron')->info('Regenerated the symlinks', $this->logContext(ContaoContext::CRON));
            }
        }

        $container->get('monolog.logger.contao.files')->info(
            'File or folder "' . $source . '" has been moved to "' . $destination . '"',
            $this->logContext(ContaoContext::FILES),
        );

        $after = $this->records($destination);
        $kept  = \count(array_intersect(array_column($before, 'uuid'), array_column($after, 'uuid')));

        $data = [
            'path'         => $source,
            'to'           => $destination,
            'type'         => $isFolder ? 'folder' : 'file',
            'records'      => \count($after),
            'uuidsKept'    => $kept === \count($before),
        ];

        if ([] !== $pathSearch['usages']) {
            $data['pathUsages'] = $pathSearch['usages'];

            // The finder stops at MAX_USAGES, as for file:delete.
            if (\count($pathSearch['usages']) >= FileUsageFinder::MAX_USAGES) {
                $data['pathUsagesCapped'] = true;
            }
        }
        if ([] !== $pathSearch['skippedTables']) {
            $data['skippedTables'] = $pathSearch['skippedTables'];
        }

        $this->outputSuccess($data);

        return Command::SUCCESS;
    }

    /**
     * Why a folder cannot be used — it runs through a symbolic link, or its case differs from
     * the folder on disk — or null when it is where the path says, below files/.
     */
    private function linkRefusal(string $folder): ?string
    {
        $jailRoot = realpath($this->projectDir . '/files');
        $real     = realpath($this->projectDir . '/' . $folder);
        $expected = false === $jailRoot ? null : self::normalise($jailRoot) . substr($folder, \strlen('files'));

        if (false !== $real && null !== $expected && self::normalise($real) === $expected) {
            return null;
        }

        return "{$folder} runs through a symbolic link, or its case differs from the folder on disk. Nothing was moved — give the real path.";
    }

    private static function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The tl_files records of the resource and everything below it.
     *
     * @return list<array{path: string, uuid: string|null}>
     */
    private function records(string $path): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT path, uuid FROM tl_files WHERE path = ? OR path LIKE ?',
            [$path, addcslashes($path, '%_\\') . '/%'],
        );

        return array_map(
            static fn (array $row): array => ['path' => (string) $row['path'], 'uuid' => null === $row['uuid'] ? null : (string) $row['uuid']],
            $rows,
        );
    }
}
