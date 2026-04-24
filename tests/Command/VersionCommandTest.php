<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionListCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionRestoreCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

class VersionCommandTest extends TestCase
{
    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function vm(): VersionManager
    {
        return $this->createMock(VersionManager::class);
    }

    private function conn(): Connection
    {
        return $this->createMock(Connection::class);
    }

    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    // --- VersionCreateCommand ---

    public function testCreateRequiresTable(): void
    {
        $cmd = new VersionCreateCommand($this->fw(), $this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('table', $out['message']);
    }

    public function testCreateRequiresId(): void
    {
        $vm = $this->vm();
        $vm->method('isAllowedTable')->willReturn(true);
        $cmd = new VersionCreateCommand($this->fw(), $vm);
        $tester = new CommandTester($cmd);
        $tester->execute(['--table' => 'tl_content']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--id', $out['message']);
    }

    public function testCreateRejectsDisallowedTable(): void
    {
        $vm = $this->vm();
        $vm->method('isAllowedTable')->willReturn(false);
        $cmd = new VersionCreateCommand($this->fw(), $vm);
        $tester = new CommandTester($cmd);
        $tester->execute(['--table' => 'tl_evil', '--id' => '1']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('allowed', $out['message']);
    }

    // --- VersionListCommand ---

    public function testListRequiresTable(): void
    {
        $cmd = new VersionListCommand($this->fw(), $this->conn());
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('table', $out['message']);
    }

    public function testListRequiresId(): void
    {
        $cmd = new VersionListCommand($this->fw(), $this->conn());
        $tester = new CommandTester($cmd);
        $tester->execute(['--table' => 'tl_content']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--id', $out['message']);
    }

    // --- VersionReadCommand ---

    public function testReadRequiresTable(): void
    {
        $cmd = new VersionReadCommand($this->fw(), $this->conn(), $this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('table', $out['message']);
    }

    public function testReadRequiresIdAndVer(): void
    {
        $cmd = new VersionReadCommand($this->fw(), $this->conn(), $this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--table' => 'tl_content']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    public function testReadCommandName(): void
    {
        $cmd = new VersionReadCommand($this->fw(), $this->conn(), $this->vm());
        $this->assertSame('contao:version:read', $cmd->getName());
    }

    // --- VersionRestoreCommand ---

    public function testRestoreRequiresTable(): void
    {
        $cmd = new VersionRestoreCommand($this->fw(), $this->vm(), $this->conn());
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('table', $out['message']);
    }

    public function testRestoreRequiresIdAndVer(): void
    {
        $cmd = new VersionRestoreCommand($this->fw(), $this->vm(), $this->conn());
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute(['--table' => 'tl_content']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    public function testRestoreRejectsDisallowedTable(): void
    {
        $vm = $this->vm();
        $vm->method('isAllowedTable')->willReturn(false);
        $cmd = new VersionRestoreCommand($this->fw(), $vm, $this->conn());
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute(['--table' => 'tl_evil', '--id' => '1', '--ver' => '2']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('allowed', $out['message']);
    }

    public function testRestoreCommandName(): void
    {
        $cmd = new VersionRestoreCommand($this->fw(), $this->vm(), $this->conn());
        $this->assertSame('contao:version:restore', $cmd->getName());
    }
}
