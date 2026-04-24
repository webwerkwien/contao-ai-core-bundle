<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

class FaqCommandTest extends TestCase
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

    // --- FaqCreateCommand ---

    public function testCreateRequiresQuestion(): void
    {
        $cmd = new FaqCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--pid' => '1']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('question', $out['message']);
    }

    public function testCreateRequiresPid(): void
    {
        $cmd = new FaqCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--question' => 'What is this?']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- FaqReadCommand ---

    public function testReadReturnsErrorForMissingRecord(): void
    {
        $cmd = new FaqReadCommand($this->fw());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- FaqDeleteCommand ---

    public function testDeleteReturnsErrorForMissingRecord(): void
    {
        $cmd = new FaqDeleteCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- FaqUpdateCommand ---

    public function testUpdateCommandName(): void
    {
        $cmd = new FaqUpdateCommand($this->fw());
        $this->assertSame('contao:faq:update', $cmd->getName());
    }
}
