<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

class ArticleCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

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
        $this->skipWithoutContaoContainer();
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
        $this->skipWithoutContaoContainer();
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
