<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FolderCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

class FolderCreateCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir();
    }

    private function makeCommand(): FolderCreateCommand
    {
        $framework = $this->createMock(ContaoFramework::class);
        $cmd = new FolderCreateCommand($framework, $this->tmpDir);
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));
        return $cmd;
    }

    public function testMissingPathReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new FolderCreateCommand($framework, $this->tmpDir));
        $tester->execute([]);

        $output = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('--path', $output['message']);
    }

    public function testPathOutsideFilesRootReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new FolderCreateCommand($framework, $this->tmpDir));
        $tester->execute(['--path' => '../escape/attempt']);

        $output = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('outside', $output['message']);
    }
}
