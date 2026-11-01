<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Files;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Path;
use Webwerkwien\ContaoAiCoreBundle\Service\Files\FileUsageFinder;

/**
 * What moving and renaming a file or folder below files/ have in common: both change a
 * path and nothing else, and Contao treats both the same way in the DBAFS.
 *
 * Mirrors `DC_Folder::cut()` of Contao 5.7/6.0: no overwriting, `Files::rename()`, then
 * `contao.filesystem.dbafs_manager->sync($source, $destination)` — which recognises the
 * change by hash and keeps the UUIDs (5.3 calls the older `Dbafs::moveResource()`; the
 * manager and its move detection exist there too) — then the symlinks for a folder, and a
 * line in the files channel. Like FolderPublishCommand, the symlinks are generated with
 * `contao.command.symlinks` directly so both log lines carry the operator.
 *
 * What Contao does not tell, and this answers: references that name the old **path** in a
 * text field (`pathUsages`). References by UUID — file pickers, `{{file::uuid}}` — keep
 * working; a path written into a text does not.
 */
abstract class AbstractFileRelocateCommand extends AbstractWriteCommand
{
    public function __construct(
        protected readonly ContaoFramework $framework,
        protected readonly Connection $connection,
        protected readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function systemLogAction(): string
    {
        return ContaoContext::FILES;
    }

    /**
     * Past tense of the command's verb, for messages: "moved" or "renamed".
     */
    abstract protected function pastTense(): string;

    /**
     * A further reason to refuse the new path, checked after the shared rules and before
     * anything is changed, with the framework initialised. Null when the path may be used.
     */
    protected function refuseDestination(string $source, string $destination): ?string
    {
        return null;
    }

    /**
     * Change the path of $source to $destination, both canonical and below files/.
     *
     * The caller has checked what only it knows: that a move target is a folder, that a
     * name is a name, that the source exists.
     *
     * @param array<string, mixed> $extra added to the answer
     */
    protected function relocate(string $source, string $destination, array $extra = []): int
    {
        $past = $this->pastTense();

        if ('.public' === basename($source) || '.public' === basename($destination)) {
            return $this->outputError("A .public file decides whether its folder is served — use file folder-publish instead of moving or renaming it. Nothing was {$past}.");
        }

        // The DBAFS manager refuses to sync a path whose first segment below files/ starts
        // with a dot ("Dot path … is not allowed"). Refused before the rename, so nothing is
        // left half-changed (since v0.23.0; FileDeleteCommand meets the same rule).
        foreach ([$source, $destination] as $path) {
            if (str_starts_with(substr($path, \strlen('files/')), '.')) {
                return $this->outputError("{$path} starts with a dot directly below files/, and Contao's file sync refuses such paths. Nothing was {$past}.");
            }
        }

        if (is_link($this->projectDir . '/' . $destination) || file_exists($this->projectDir . '/' . $destination)) {
            return $this->outputError("{$destination} already exists. Nothing was {$past} — Contao does not overwrite on a move either.");
        }

        // No linked folder on either way: through one, the rename would act on the link's
        // target and tl_files would record paths that do not exist (as in FileDeleteCommand).
        foreach ([\dirname($source), \dirname($destination)] as $folder) {
            $refusal = $this->linkRefusal($folder);

            if (null !== $refusal) {
                return $this->outputError($refusal . " Nothing was {$past}.");
            }
        }

        $this->framework->initialize();

        $refusal = $this->refuseDestination($source, $destination);

        if (null !== $refusal) {
            return $this->outputError($refusal);
        }

        $isFolder = is_dir($this->projectDir . '/' . $source) && !is_link($this->projectDir . '/' . $source);

        // Text that names the old path breaks with the change; UUID references do not.
        // Searched without UUIDs, the finder reports exactly the path mentions.
        $before        = $this->records($source);
        $pathResources = array_map(
            static fn (array $record): array => ['path' => $record['path'], 'uuid' => null],
            $before ?: [['path' => $source]],
        );
        $pathSearch = (new FileUsageFinder($this->connection))->find($pathResources);

        Files::getInstance()->rename($source, $destination);
        clearstatcache();

        if (!file_exists($this->projectDir . '/' . $destination) && !is_link($this->projectDir . '/' . $destination)) {
            return $this->outputError("{$source} could not be {$past} to {$destination}. Nothing was changed.");
        }

        $container = System::getContainer();

        try {
            $container->get('contao.filesystem.dbafs_manager')->sync($source, $destination);
        } catch (\Throwable $e) {
            $message = \sprintf(
                '%s was %s to %s, but tl_files could not be updated (%s). Run contao:filesync%s — UUIDs may change.',
                $source,
                $past,
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
            'File or folder "' . $source . '" has been ' . $past . ' to "' . $destination . '"',
            $this->logContext(ContaoContext::FILES),
        );

        $after = $this->records($destination);
        $kept  = \count(array_intersect(array_column($before, 'uuid'), array_column($after, 'uuid')));

        $data = $extra + [
            'path'      => $source,
            'to'        => $destination,
            'type'      => $isFolder ? 'folder' : 'file',
            'records'   => \count($after),
            'uuidsKept' => $kept === \count($before),
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

        return "{$folder} runs through a symbolic link, or its case differs from the folder on disk — give the real path.";
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
