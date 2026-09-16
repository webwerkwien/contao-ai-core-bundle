<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Automator;
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
use Symfony\Component\Filesystem\Path;
use Webwerkwien\ContaoAiCoreBundle\Service\Files\FileUsageFinder;

/**
 * Delete a file or folder below files/ — as the back end does, and not while it is used.
 *
 * Until v0.18.0 there was no way to delete a file through the bundle (Nr. 46 of the ConpAI
 * 1.0 acceptance test, 2026-09-16). See FileDeleteCommandTest.
 *
 * Mirrors `DC_Folder::delete()` of Contao 5.7/6.0: the resource first — `Files::rrdir()`
 * plus the web dir symlink for a folder, `Files::delete()` for a file — then the script
 * cache for css/js, then `contao.filesystem.dbafs_manager->sync()`, which compares
 * `tl_files` with what is really left on disk. (5.3 calls the older
 * `Dbafs::deleteResource()`; the DBAFS manager exists there too.) Before that,
 * FileUsageFinder: a file or folder that is still referenced is refused unless `--force`
 * is given, because Contao checks nothing and there is no `tl_undo` for files.
 *
 * Since v0.19.0 the path is canonicalised before anything is looked up (`files/a//b.png`
 * found no DBAFS record and skipped the usage check), a symbolic link is deleted as a link
 * instead of emptying its target, and a path running through a linked folder is refused.
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

    /**
     * The path as tl_files stores it: `/` only, no empty or `.` segments. Null for `..`,
     * anything outside files/ and files/ itself.
     *
     * `files/a//b.png` and `files/a/./b.png` reach the same file as `files/a/b.png`, but
     * the DBAFS lookup is by the literal string — so until v0.19.0 the record was not
     * found, the UUID not searched for, and the record left behind (review 2026-09-16).
     */
    public static function canonicalPath(string $path): ?string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment || str_contains($segment, "\0")) {
                return null;
            }
            $segments[] = $segment;
        }

        if (\count($segments) < 2 || 'files' !== $segments[0]) {
            return null;
        }

        return implode('/', $segments);
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = self::canonicalPath((string) $path);
        if (null === $path) {
            return $this->outputError('Path must be a file or folder below files/ and must not contain ".." — files/ itself cannot be deleted.');
        }

        $absolute = $this->projectDir . '/' . $path;
        $isLink   = is_link($absolute);

        if (!$isLink && !file_exists($absolute)) {
            return $this->outputError("File or folder not found: {$path}. Nothing was deleted.");
        }

        // The folder holding the resource must be where the path says, below files/ — no
        // linked folder on the way. Through one, the target's content would be deleted and
        // its tl_files records neither checked for usages nor removed.
        $jailRoot = realpath($this->projectDir . '/files');
        $parent   = realpath(\dirname($absolute));
        $expected = false === $jailRoot ? null : self::normalise($jailRoot) . substr(\dirname($path), \strlen('files'));

        if (false === $parent || null === $expected || self::normalise($parent) !== $expected) {
            $inside = false !== $parent && false !== $jailRoot && str_starts_with(self::normalise($parent) . '/', self::normalise($jailRoot) . '/');

            return $this->outputError($inside
                ? "{$path} runs through a symbolic link, or its case differs from the folder on disk. Nothing was deleted — delete the link itself, or give the real path."
                : 'Path must be a file or folder below files/ — it resolves outside of it.');
        }

        $this->framework->initialize();

        $webDir    = StringUtil::stripRootDir((string) System::getContainer()->getParameter('contao.web_dir'));
        $isFolder  = !$isLink && is_dir($absolute);
        $records   = $this->records($path);
        $resources = [] === $records ? [['path' => $path, 'uuid' => null]] : $records;
        $search    = (new FileUsageFinder($this->connection))->find($resources);
        $usages    = $search['usages'];
        $force     = (bool) $this->input->getOption('force');

        if ([] !== $usages && !$force) {
            $answer = [
                'status'  => 'error',
                'message' => \sprintf(
                    '%s is still used in %d place(s)%s. Nothing was deleted. Remove the references first, or pass --force — a deleted file cannot be restored.',
                    $path,
                    \count($usages),
                    \count($usages) >= FileUsageFinder::MAX_USAGES ? ' (at least)' : '',
                ),
                'usages'  => $usages,
            ];

            if ([] !== $search['skippedTables']) {
                $answer['skippedTables'] = $search['skippedTables'];
            }

            $answer['code'] = 1;

            $this->output->writeln(json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return Command::FAILURE;
        }

        // DC_Folder::delete(): the resource first, then the database.
        $files = Files::getInstance();

        if ($isFolder) {
            $files->rrdir($path);
        } else {
            // A link is removed as a link (Symfony's Filesystem::remove unlinks it).
            $files->delete($path);
        }

        // A public folder's symlink in the web dir — also for a folder that was a link.
        if (($isFolder || $isLink) && is_link($this->projectDir . '/' . $webDir . '/' . $path)) {
            $files->delete($webDir . '/' . $path);
        }

        clearstatcache();

        if (\in_array(strtolower(Path::getExtension($path)), ['css', 'scss', 'less', 'js'], true)) {
            (new Automator())->purgeScriptCache();
        }

        $gone = !is_link($absolute) && !file_exists($absolute);

        // Also after a partial failure: what did get deleted must leave tl_files. From here
        // on something is gone, so every answer says so and is logged.
        $dotPath = str_starts_with(substr($path, \strlen('files/')), '.');

        try {
            if (!$dotPath) {
                System::getContainer()->get('contao.filesystem.dbafs_manager')->sync($path);
            } elseif ($gone) {
                // The DBAFS manager refuses to sync a path starting with a dot directly below
                // files/ ("Dot path … is not allowed") — yet a filesync records dot *folders*
                // and their content (`files/.hidden`, verified on 6.0.0). Pre-release reviews,
                // 2026-09-16: first `.htaccess` answered with that exception, then skipping the
                // sync left the rows of `files/.hidden` behind. The legacy call has no such
                // rule, exists from 5.3 to 6.0, and is safe once nothing is left on disk.
                Dbafs::deleteResource($path);
            }
        } catch (\Throwable $e) {
            return $this->failAfterDeleting($path, \sprintf(
                '%s %s, but tl_files could not be updated (%s). Run contao:filesync.',
                $path,
                $gone ? 'was deleted' : 'was deleted in part',
                $e->getMessage(),
            ));
        }

        $removed = \count($records) - \count($this->records($path));

        if (!$gone) {
            return $this->failAfterDeleting($path, \sprintf(
                '%s could not be deleted completely%s. %s',
                $path,
                $isFolder ? ' — some of its content may be gone' : '',
                $dotPath
                    ? 'tl_files was not updated (a dot path cannot be synchronised on its own) — run contao:filesync.'
                    : "tl_files was synchronised with what is left ({$removed} record(s) removed).",
            ), $removed);
        }

        $data = [
            'path'     => $path,
            'type'     => $isLink ? 'link' : ($isFolder ? 'folder' : 'file'),
            'deleted'  => true,
            'records'  => $removed,
            'undoable' => false,
        ];

        if ([] !== $usages) {
            $data['usages'] = $usages;
        }
        if ([] !== $search['skippedTables']) {
            $data['skippedTables'] = $search['skippedTables'];
        }

        $this->outputSuccess($data);

        return Command::SUCCESS;
    }

    /**
     * An error answer for a run that did delete something — logged like a success, because
     * the audit trail has to show that files went, whatever the answer says.
     */
    private function failAfterDeleting(string $path, string $message, ?int $removed = null): int
    {
        $this->logSuccess(['path' => $path, 'deleted' => 'partly or without tl_files update', 'records' => $removed, 'error' => $message]);

        return $this->outputError($message);
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
