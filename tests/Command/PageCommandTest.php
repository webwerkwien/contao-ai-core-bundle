<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PagePublishCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

class PageCommandTest extends TestCase
{
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

    // --- PageCreateCommand ---

    public function testCreateRequiresTitle(): void
    {
        $cmd = new PageCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('title', $out['message']);
    }

    // --- PageReadCommand ---

    public function testReadReturnsErrorForMissingRecord(): void
    {
        $cmd = new PageReadCommand($this->fw());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- PageDeleteCommand ---

    public function testDeleteReturnsErrorForMissingRecord(): void
    {
        $cmd = new PageDeleteCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- PageUpdateCommand ---

    public function testUpdateCommandName(): void
    {
        $cmd = new PageUpdateCommand($this->fw());
        $this->assertSame('contao:page:update', $cmd->getName());
    }

    // --- PagePublishCommand ---

    public function testPublishReturnsErrorForMissingRecord(): void
    {
        $cmd = new PagePublishCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    public function testPublishCommandName(): void
    {
        $cmd = new PagePublishCommand($this->fw());
        $this->assertSame('contao:page:publish', $cmd->getName());
    }
}
