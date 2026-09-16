<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\Dbafs;
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

    // File operations belong under the FILES action, the same bucket the back
    // end's own file manager writes to.
    protected function systemLogAction(): string
    {
        return ContaoContext::FILES;
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

        // A new folder goes through the DBAFS, as file write and the back end do:
        // UUID, parent entries, contents. Until v0.12.0 this built a FilesModel by
        // hand and never set a UUID — see FolderCreateDbafsTest.
        $model    = FilesModel::findByPath($path);
        $repaired = [];

        if (null === $model) {
            Dbafs::addResource($path);
        } else {
            $this->repairFolder($model, $repaired);
        }

        $this->outputSuccess(['path' => $path, 'created' => !$existed, 'repaired' => $repaired]);
        return Command::SUCCESS;
    }

    /**
     * Give an existing folder record what the DBAFS owes it, and its children the
     * link to it.
     *
     * Deleting and re-adding is not an option: `Dbafs::addResource()` would then
     * create a second record for every child, and a child's UUID may already be
     * referenced by content elements. So the record is repaired in place:
     *
     *  1. a UUID, if it has none (`Database::getUuid()`, as the DBAFS does)
     *  2. the right pid — NULL directly under files/, else the parent's UUID
     *     (a parent without one is repaired first)
     *  3. direct children whose pid is missing or wrong are re-attached, and a
     *     child folder without a UUID is repaired the same way
     *
     * @param list<string> $repaired paths that were changed, collected for the answer
     */
    private function repairFolder(FilesModel $folder, array &$repaired): void
    {
        $changed = false;

        if (self::isMissingUuid($folder->uuid)) {
            $folder->uuid = Database::getInstance()->getUuid();
            $changed      = true;
        }

        $expectedPid = $this->expectedPid((string) $folder->path, $repaired);
        if (!self::sameUuid($folder->pid, $expectedPid)) {
            $folder->pid = $expectedPid;
            $changed     = true;
        }

        if ($changed) {
            $folder->tstamp = time();
            $folder->save();
            $repaired[] = (string) $folder->path;
        }

        $children = FilesModel::findBy(['path LIKE ?'], [$folder->path . '/%']);
        if (null === $children) {
            return;
        }

        foreach ($children as $child) {
            if (\dirname((string) $child->path) !== $folder->path) {
                continue; // grandchildren are their own folder's business
            }

            if ('folder' === $child->type && self::isMissingUuid($child->uuid)) {
                $this->repairFolder($child, $repaired);
                continue;
            }

            if (!self::sameUuid($child->pid, $folder->uuid)) {
                $child->pid    = $folder->uuid;
                $child->tstamp = time();
                $child->save();
                $repaired[] = (string) $child->path;
            }
        }
    }

    /**
     * NULL directly under files/, the parent folder's UUID below it.
     *
     * @param list<string> $repaired
     */
    private function expectedPid(string $path, array &$repaired): ?string
    {
        $parentPath = \dirname($path);

        if ('files' === $parentPath || '.' === $parentPath || '' === $parentPath) {
            return null;
        }

        $parent = FilesModel::findByPath($parentPath);
        if (null === $parent) {
            return null;
        }

        if (self::isMissingUuid($parent->uuid)) {
            $this->repairFolder($parent, $repaired);
        }

        return $parent->uuid;
    }

    /**
     * Whether a stored UUID is absent: NULL, empty, all zeros, or not 16 bytes.
     */
    private static function isMissingUuid(mixed $value): bool
    {
        return !\is_string($value) || 16 !== \strlen($value) || str_repeat("\0", 16) === $value;
    }

    private static function sameUuid(mixed $stored, ?string $expected): bool
    {
        // Directly under files/ the pid is NULL — not '' and not sixteen zeros,
        // which is what the hand-built record used to leave.
        if (null === $expected) {
            return null === $stored;
        }

        return \is_string($stored) && $stored === $expected;
    }
}
