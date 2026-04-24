<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\FileReadCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\FileWriteCommand;
use Webwerkwien\ContaoCliBridgeBundle\Service\VersionManager;

class FileCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/file_cmd_test_' . uniqid('', true);
        mkdir($this->tmpDir . '/files', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function vm(): VersionManager
    {
        return $this->createMock(VersionManager::class);
    }

    // =====================
    // FileReadCommand tests
    // =====================

    public function testReadRequiresPath(): void
    {
        $cmd = new FileReadCommand($this->fw(), $this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('path', $out['message']);
    }

    public function testReadRejectsDotDotPath(): void
    {
        $cmd = new FileReadCommand($this->fw(), $this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => '../../etc/passwd']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('..', $out['message']);
    }

    public function testReadRejectsPathWithoutFilesPrefix(): void
    {
        $cmd = new FileReadCommand($this->fw(), $this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'other/file.txt']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('files/', $out['message']);
    }

    public function testReadReturnsErrorForNonExistentFile(): void
    {
        $cmd = new FileReadCommand($this->fw(), $this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'files/nonexistent.txt']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    public function testReadReturnsContentForValidFile(): void
    {
        file_put_contents($this->tmpDir . '/files/hello.txt', 'Hello World');
        $cmd = new FileReadCommand($this->fw(), $this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'files/hello.txt']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertSame('Hello World', $out['content']);
    }

    public function testReadRejectsSymlinkEscapingRoot(): void
    {
        // Create a regular file outside the project root to link to
        $externalFile = sys_get_temp_dir() . '/bridge_test_external_' . uniqid() . '.txt';
        file_put_contents($externalFile, 'external');

        $linkPath = $this->tmpDir . '/files/escape_link.txt';
        if (!@symlink($externalFile, $linkPath)) {
            @unlink($externalFile);
            $this->markTestSkipped('Cannot create symlinks in this environment');
        }

        $cmd = new FileReadCommand($this->fw(), $this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'files/escape_link.txt']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('Access denied', $out['message']);

        unlink($linkPath);
        unlink($externalFile);
    }

    // ======================
    // FileWriteCommand tests
    // ======================

    public function testWriteRequiresPath(): void
    {
        $cmd = new FileWriteCommand($this->fw(), $this->tmpDir);
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--source' => '/tmp/something.txt']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('path', $out['message']);
    }

    public function testWriteRequiresSource(): void
    {
        $cmd = new FileWriteCommand($this->fw(), $this->tmpDir);
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'files/test.txt']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('source', $out['message']);
    }

    public function testWriteRejectsDotDotInPath(): void
    {
        $cmd = new FileWriteCommand($this->fw(), $this->tmpDir);
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => '../../etc/cron.d/evil', '--source' => '/tmp/x.txt']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('..', $out['message']);
    }

    public function testWriteRejectsSourceOutsideAllowedDirs(): void
    {
        // Source not under /tmp/ or var/bridge-uploads/ → error
        $cmd = new FileWriteCommand($this->fw(), $this->tmpDir);
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'files/safe.txt', '--source' => '/etc/hosts']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('source', $out['message']);
    }
}
