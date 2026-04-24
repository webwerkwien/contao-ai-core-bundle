<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\EventCreateCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\EventDeleteCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\EventReadCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\EventUpdateCommand;
use Webwerkwien\ContaoCliBridgeBundle\Service\VersionManager;

class EventCommandTest extends TestCase
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

    // --- EventCreateCommand ---

    public function testCreateRequiresTitle(): void
    {
        $cmd = new EventCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--pid' => '1']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('title', $out['message']);
    }

    public function testCreateRequiresPid(): void
    {
        $cmd = new EventCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--title' => 'My Event']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- EventReadCommand ---

    public function testReadReturnsErrorForMissingRecord(): void
    {
        $cmd = new EventReadCommand($this->fw());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- EventDeleteCommand ---

    public function testDeleteReturnsErrorForMissingRecord(): void
    {
        $cmd = new EventDeleteCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- EventUpdateCommand ---

    public function testUpdateCommandName(): void
    {
        $cmd = new EventUpdateCommand($this->fw());
        $this->assertSame('contao:event:update', $cmd->getName());
    }
}
