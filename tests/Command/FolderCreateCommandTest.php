<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoCliBridgeBundle\Command\FolderCreateCommand;
use Contao\CoreBundle\Framework\ContaoFramework;

class FolderCreateCommandTest extends TestCase
{
    public function testMissingPathReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new FolderCreateCommand($framework, sys_get_temp_dir()));
        $tester->execute([]);

        $output = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('--path', $output['message']);
    }

    public function testPathOutsideFilesRootReturnsError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new FolderCreateCommand($framework, sys_get_temp_dir()));
        $tester->execute(['--path' => '../escape/attempt']);

        $output = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('outside', $output['message']);
    }
}
