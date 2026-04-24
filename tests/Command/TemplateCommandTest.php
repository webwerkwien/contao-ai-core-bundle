<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\TemplateListCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\TemplateReadCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\TemplateWriteCommand;

class TemplateCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tpl_cmd_test_' . uniqid('', true);
        mkdir($this->tmpDir . '/templates', 0775, true);
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

    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    // ========================
    // TemplateListCommand tests
    // ========================

    public function testListCommandName(): void
    {
        $cmd = new TemplateListCommand($this->tmpDir);
        $this->assertSame('contao:template:list', $cmd->getName());
    }

    public function testListReturnsEmptyArrayWhenNoTemplates(): void
    {
        $cmd = new TemplateListCommand($this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertIsArray($out['templates']);
        $this->assertEmpty($out['templates']);
    }

    public function testListReturnsOkWhenTemplatesDirMissing(): void
    {
        // Point to a dir that has no templates/ subdirectory
        $cmd = new TemplateListCommand(sys_get_temp_dir() . '/nonexistent_' . uniqid('', true));
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertSame([], $out['templates']);
    }

    // ========================
    // TemplateReadCommand tests
    // ========================

    public function testReadRequiresPath(): void
    {
        $cmd = new TemplateReadCommand($this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('path', $out['message']);
    }

    public function testReadRejectsDotDotInPath(): void
    {
        $cmd = new TemplateReadCommand($this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'templates/../../../etc/passwd']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('..', $out['message']);
    }

    public function testReadRejectsPathWithoutTemplatesPrefix(): void
    {
        $cmd = new TemplateReadCommand($this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'other/file.html.twig']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('templates/', $out['message']);
    }

    public function testReadReturnsErrorForNonExistentTemplate(): void
    {
        $cmd = new TemplateReadCommand($this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'templates/nonexistent.html.twig']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    public function testReadReturnsContentForValidTemplate(): void
    {
        file_put_contents($this->tmpDir . '/templates/test.html.twig', '<p>Hello</p>');
        $cmd = new TemplateReadCommand($this->tmpDir);
        $tester = new CommandTester($cmd);
        $tester->execute(['--path' => 'templates/test.html.twig']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertSame('<p>Hello</p>', $out['content']);
    }

    // =========================
    // TemplateWriteCommand tests
    // =========================

    public function testWriteRequiresModeBaseSource(): void
    {
        $cmd = new TemplateWriteCommand($this->tmpDir);
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('mode', $out['message']);
    }

    public function testWriteRejectsAbsoluteBase(): void
    {
        $cmd = new TemplateWriteCommand($this->tmpDir);
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute([
            '--mode'   => 'override',
            '--base'   => '/etc/passwd',
            '--source' => '/tmp/x.html.twig',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('base', $out['message']);
    }

    public function testWriteRejectsDotDotInBase(): void
    {
        $cmd = new TemplateWriteCommand($this->tmpDir);
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute([
            '--mode'   => 'override',
            '--base'   => '../../../etc/evil',
            '--source' => '/tmp/x.html.twig',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    public function testWriteRejectsInvalidMode(): void
    {
        $cmd = new TemplateWriteCommand($this->tmpDir);
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute([
            '--mode'   => 'invalid',
            '--base'   => 'content_element/text',
            '--source' => '/tmp/x.html.twig',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('mode', $out['message']);
    }

    public function testWriteRequiresNameForVariantMode(): void
    {
        $cmd = new TemplateWriteCommand($this->tmpDir);
        $cmd->setLogger($this->logger());
        $tester = new CommandTester($cmd);
        $tester->execute([
            '--mode'   => 'variant',
            '--base'   => 'content_element/text',
            '--source' => '/tmp/x.html.twig',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('name', $out['message']);
    }
}
