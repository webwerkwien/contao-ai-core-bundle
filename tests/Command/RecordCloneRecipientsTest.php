<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\EntityClonerInterface;

/**
 * `--with-recipients` reaches the newsletter cloner and nothing else (v1.8.0).
 */
class RecordCloneRecipientsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $calls
     */
    private function command(array &$calls): RecordCloneCommand
    {
        $cloner = $this->createMock(EntityClonerInterface::class);
        $cloner->method('supports')->willReturn(true);
        $cloner->method('clone')->willReturnCallback(static function (int $id, array $mods, string $operator, array $options) use (&$calls): array {
            $calls[] = $options;

            return ['id' => 5, 'table' => 'tl_newsletter_channel', 'count' => 0, 'ignored_modifications' => []];
        });

        return new RecordCloneCommand([$cloner]);
    }

    public function testRecipientsAreOffUnlessAskedFor(): void
    {
        $calls  = [];
        $tester = new CommandTester($this->command($calls));

        $tester->execute(['--source-table' => 'tl_newsletter_channel', '--source-id' => '3']);
        $tester->execute(['--source-table' => 'tl_newsletter_channel', '--source-id' => '3', '--with-recipients' => true]);

        $this->assertFalse($calls[0]['recipients']);
        $this->assertTrue($calls[1]['recipients']);
    }

    public function testRecipientsForAnotherTableAreRefusedBeforeAnythingIsCloned(): void
    {
        $calls  = [];
        $tester = new CommandTester($this->command($calls));

        $code   = $tester->execute(['--source-table' => 'tl_form', '--source-id' => '4', '--with-recipients' => true]);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame(1, $code);
        $this->assertSame('error', $answer['status']);
        $this->assertStringContainsString('tl_newsletter_channel', $answer['message']);
        $this->assertSame([], $calls);
    }
}
