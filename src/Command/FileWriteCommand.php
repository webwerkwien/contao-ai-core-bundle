<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
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

        // TODO: restrict to var/bridge-uploads/ once agent scp_upload is updated
        $realSource = realpath($source);
        $uploadDir  = rtrim($this->projectDir, '/') . '/var/bridge-uploads/';
        $realUpload = realpath($uploadDir);
        if ($realUpload === false) {
            return $this->outputError('Upload directory var/bridge-uploads/ does not exist on this server');
        }
        $realUpload = rtrim($realUpload, '/') . '/';

        if (
            $realSource === false
            || (
                !str_starts_with($realSource, '/tmp/')
                && !str_starts_with($realSource, $realUpload)
            )
        ) {
            return $this->outputError('--source must be under /tmp/ or var/bridge-uploads/');
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
            $this->versionManager->createVersion('tl_files', (int) $filesModel->id);
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
            $version = false;
        }

        $this->outputSuccess([
            'path'    => $path,
            'bytes'   => $bytes,
            'version' => $version,
        ]);

        return Command::SUCCESS;
    }
}
