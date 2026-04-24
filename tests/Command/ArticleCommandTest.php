<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\ArticleCreateCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\ArticleDeleteCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\ArticleReadCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\ArticleUpdateCommand;
use Webwerkwien\ContaoCliBridgeBundle\Service\VersionManager;

class ArticleCommandTest extends TestCase
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

    // --- ArticleCreateCommand ---

    public function testCreateRequiresTitle(): void
    {
        $cmd = new ArticleCreateCommand($this->fw());
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
        $cmd = new ArticleCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--title' => 'Test Article']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- ArticleReadCommand ---

    public function testReadReturnsErrorForMissingRecord(): void
    {
        // id=0 passes argument validation but finds no record → error response
        $cmd = new ArticleReadCommand($this->fw());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- ArticleDeleteCommand ---

    public function testDeleteReturnsErrorForMissingRecord(): void
    {
        $cmd = new ArticleDeleteCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- ArticleUpdateCommand ---

    public function testUpdateCommandName(): void
    {
        $cmd = new ArticleUpdateCommand($this->fw());
        $this->assertSame('contao:article:update', $cmd->getName());
    }
}
