<?php
namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\FileMetaUpdateCommand;
use Contao\CoreBundle\Framework\ContaoFramework;

class FileMetaUpdateCommandTest extends TestCase
{
    public function testMissingPathReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $tester    = new CommandTester(new FileMetaUpdateCommand($framework));
        $tester->execute([]);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--path', $out['message']);
    }

    public function testNoFieldsReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $tester    = new CommandTester(new FileMetaUpdateCommand($framework));
        $tester->execute(['--path' => 'files/photo.jpg']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--set', $out['message']);
    }
}
