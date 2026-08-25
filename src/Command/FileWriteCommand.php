<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Dbafs;
use Contao\FilesModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:file:write', description: 'Write a text file to files/ and create a tl_version snapshot')]
class FileWriteCommand extends AbstractWriteCommand
{
    private const MAX_SOURCE_BYTES = 10485760; // 10 MB

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
        $this
            ->addOption('path',   null, InputOption::VALUE_REQUIRED, 'Destination path relative to Contao root, e.g. files/scripts/style.css')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Absolute path of temp file on the server to read content from');
    }

    protected function doExecute(array $fields): int
    {
        $path   = $this->input->getOption('path');
        $source = $this->input->getOption('source');

        if (!$path || !$source) {
            return $this->outputError('--path and --source are required');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must start with files/ and must not contain ".."');
        }

        $realSource = realpath($source);
        $uploadDir  = rtrim($this->projectDir, '/') . '/var/bridge-uploads/';
        $realUpload = realpath($uploadDir);
        if ($realUpload === false) {
            return $this->outputError('Upload directory var/bridge-uploads/ does not exist on this server');
        }
        $realUpload = rtrim($realUpload, '/') . '/';

        if ($realSource === false || !str_starts_with($realSource, $realUpload)) {
            return $this->outputError('--source must be under var/bridge-uploads/');
        }

        if (!is_file($realSource)) {
            return $this->outputError("Source file not found");
        }

        if (filesize($realSource) > self::MAX_SOURCE_BYTES) {
            return $this->outputError('Source file exceeds maximum allowed size of 10 MB');
        }

        $content = file_get_contents($realSource);
        if ($content === false) {
            return $this->outputError('Cannot read source file');
        }

        $this->framework->initialize();

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

        // Create parent directories if needed
        $dir = dirname($absPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return $this->outputError("Cannot create directory for: {$path}");
        }

        $filesModel = FilesModel::findByPath($path);
        if ($filesModel !== null) {
            // Snapshot the record before overwrite — Contao convention: version = pre-change state
            $this->versionManager->createVersion('tl_files', (int) $filesModel->id, $this->resolveOperator());
        }

        if (file_put_contents($absPath, $content) === false) {
            return $this->outputError('Cannot write file');
        }

        $bytes = strlen($content);

        if ($filesModel !== null) {
            $filesModel->tstamp = time();
            $filesModel->hash   = md5($content);
            $filesModel->save();
            $version = true;
        } else {
            // New file: register it in the DBAFS so it receives a tl_files
            // record (and thus a UUID). Without this the file exists on disk
            // but stays invisible to Contao until the next contao:filesync —
            // which is exactly what blocks referencing it in a content element.
            $version = false;
            try {
                $filesModel = Dbafs::addResource($path);
            } catch (\Throwable $e) {
                $this->logger->warning('contao:file:write DBAFS sync failed', [
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]);
                $filesModel = null;
            }
        }

        // Expose the file UUID so callers can reference the file (e.g. set
        // tl_content.singleSRC) without a second lookup. Null only if the
        // DBAFS sync above failed.
        $uuid = ($filesModel !== null && $filesModel->uuid !== null)
            ? StringUtil::binToUuid($filesModel->uuid)
            : null;

        $this->outputSuccess([
            'path'    => $path,
            'bytes'   => $bytes,
            'version' => $version,
            'uuid'    => $uuid,
        ]);

        return Command::SUCCESS;
    }
}
