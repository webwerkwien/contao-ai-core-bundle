<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\FileProcessCommand;
use Contao\CoreBundle\Framework\ContaoFramework;

class FileProcessCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fp_test_' . uniqid();
        mkdir($this->tmpDir . '/files', 0775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/files/*'));
        rmdir($this->tmpDir . '/files');
        rmdir($this->tmpDir);
    }

    private function makeCommand(): FileProcessCommand
    {
        $framework = $this->createMock(ContaoFramework::class);
        return new FileProcessCommand($framework, $this->tmpDir);
    }

    public function testMissingPathReturnsError(): void
    {
        $tester = new CommandTester($this->makeCommand());
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--path', $out['message']);
    }

    public function testDisallowedExtensionReturnsError(): void
    {
        $file = $this->tmpDir . '/files/virus.exe';
        file_put_contents($file, 'fake');

        $tester = new CommandTester($this->makeCommand());
        $tester->execute([
            '--path'          => 'files/virus.exe',
            '--allowed-types' => 'jpg,png,gif',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not allowed', $out['message']);
    }

    public function testValidJpegPassesWithoutResize(): void
    {
        // Create a tiny valid JPEG (1x1 pixel)
        $img  = imagecreatetruecolor(1, 1);
        $file = $this->tmpDir . '/files/tiny.jpg';
        imagejpeg($img, $file, 90);
        imagedestroy($img);

        $tester = new CommandTester($this->makeCommand());
        $tester->execute([
            '--path'          => 'files/tiny.jpg',
            '--allowed-types' => 'jpg,jpeg,png',
            '--max-width'     => '800',
            '--max-height'    => '600',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertFalse($out['resized']);
    }

    public function testOversizedImageGetsResized(): void
    {
        $img  = imagecreatetruecolor(2000, 1500);
        $file = $this->tmpDir . '/files/big.jpg';
        imagejpeg($img, $file, 85);
        imagedestroy($img);

        $tester = new CommandTester($this->makeCommand());
        $tester->execute([
            '--path'          => 'files/big.jpg',
            '--allowed-types' => 'jpg,jpeg',
            '--max-width'     => '800',
            '--max-height'    => '600',
        ]);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertTrue($out['resized']);

        [$w, $h] = getimagesize($file);
        $this->assertLessThanOrEqual(800, $w);
        $this->assertLessThanOrEqual(600, $h);
    }
}
