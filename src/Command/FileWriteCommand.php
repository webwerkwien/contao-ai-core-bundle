<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'contao:file:write', description: 'Write a text file to files/ and create a tl_version snapshot')]
class FileWriteCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path',   null, InputOption::VALUE_REQUIRED, 'Destination path relative to Contao root, e.g. files/scripts/style.css')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Absolute path of temp file on the server to read content from');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path   = $input->getOption('path');
        $source = $input->getOption('source');

        if (!$path || !$source) {
            $output->writeln(json_encode(['status' => 'error', 'message' => '--path and --source are required']));
            return self::FAILURE;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            $output->writeln(json_encode(['status' => 'error', 'message' => 'Path must start with files/ and must not contain ".."']));
            return self::FAILURE;
        }

        if (!is_file($source)) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Source file not found: {$source}"]));
            return self::FAILURE;
        }

        $content = file_get_contents($source);
        if ($content === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Cannot read source file: {$source}"]));
            return self::FAILURE;
        }

        $this->framework->initialize();

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;

        // Create parent directories if needed
        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filesModel = FilesModel::findByPath($path);
        if ($filesModel !== null) {
            $fileId = (int) $filesModel->id;
            $fileRow = $this->connection->fetchAssociative("SELECT * FROM tl_files WHERE id = ?", [$fileId]);
            if ($fileRow !== false) {
                $max = (int) $this->connection->fetchOne(
                    'SELECT MAX(version) FROM tl_version WHERE fromTable = ? AND pid = ?',
                    ['tl_files', $fileId]
                );
                $this->connection->executeStatement(
                    'UPDATE tl_version SET active = 0 WHERE fromTable = ? AND pid = ?',
                    ['tl_files', $fileId]
                );
                $this->connection->insert('tl_version', [
                    'tstamp'    => time(),
                    'fromTable' => 'tl_files',
                    'pid'       => $fileId,
                    'version'   => $max + 1,
                    'username'  => 'cli-agent',
                    'active'    => 1,
                    'data'      => serialize($fileRow),
                ]);
            }
        }

        if (file_put_contents($absPath, $content) === false) {
            $output->writeln(json_encode(['status' => 'error', 'message' => "Cannot write file: {$path}"]));
            return self::FAILURE;
        }

        $bytes = strlen($content);

        if ($filesModel !== null) {
            $filesModel->tstamp = time();
            $filesModel->hash   = md5_file($absPath) ?: '';
            $filesModel->save();
            $version = true;
        } else {
            $version = false;
        }

        $output->writeln(json_encode([
            'status'  => 'ok',
            'path'    => $path,
            'bytes'   => $bytes,
            'version' => $version,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
