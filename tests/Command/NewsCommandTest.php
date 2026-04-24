<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\NewsCreateCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\NewsDeleteCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\NewsReadCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\NewsUpdateCommand;
use Webwerkwien\ContaoCliBridgeBundle\Service\VersionManager;

class NewsCommandTest extends TestCase
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

    // --- NewsCreateCommand ---
    // Note: NewsCreateCommand uses --headline (not --title)

    public function testCreateRequiresHeadline(): void
    {
        $cmd = new NewsCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--pid' => '1']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('headline', $out['message']);
    }

    public function testCreateRequiresPid(): void
    {
        $cmd = new NewsCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--headline' => 'Breaking News']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- NewsReadCommand ---

    public function testReadReturnsErrorForMissingRecord(): void
    {
        $cmd = new NewsReadCommand($this->fw());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- NewsDeleteCommand ---

    public function testDeleteReturnsErrorForMissingRecord(): void
    {
        $cmd = new NewsDeleteCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- NewsUpdateCommand ---

    public function testUpdateCommandName(): void
    {
        $cmd = new NewsUpdateCommand($this->fw());
        $this->assertSame('contao:news:update', $cmd->getName());
    }
}
