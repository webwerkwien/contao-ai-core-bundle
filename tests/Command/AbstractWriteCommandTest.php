<?php

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Monolog\ContaoContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FileMetaUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FileProcessCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FileWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FolderCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;
use Symfony\Component\Console\Command\Command;

class ConcreteCommand extends AbstractWriteCommand
{
    protected function configure(): void
    {
        $this->setName('test:command');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        $this->outputSuccess(['id' => 1, 'fields' => $fields]);
        return Command::SUCCESS;
    }
}

class AbstractWriteCommandTest extends TestCase
{
    /**
     * Regression: '' is not a falsy tinyint, it is an invalid one. Under
     * STRICT_ALL_TABLES it threw instead of unpublishing.
     */
    public function testBooleanFlagNeverYieldsAnEmptyString(): void
    {
        $cmd = new ConcreteCommand();
        $this->assertSame('1', $cmd->booleanFlag(true));
        $this->assertSame('0', $cmd->booleanFlag(false));
        $this->assertNotSame('', $cmd->booleanFlag(false));
    }

    public function testParseSetOptions(): void
    {
        $cmd = new ConcreteCommand();
        $parsed = $cmd->parseSetOptions(['email=new@example.com', 'language=de']);
        $this->assertSame(['email' => 'new@example.com', 'language' => 'de'], $parsed);
    }

    public function testParseSetOptionsIgnoresInvalid(): void
    {
        $cmd = new ConcreteCommand();
        $parsed = $cmd->parseSetOptions(['invalid-without-equals']);
        $this->assertSame([], $parsed);
    }

    public function testParseSetOptionsValueWithEquals(): void
    {
        $cmd = new ConcreteCommand();
        $parsed = $cmd->parseSetOptions(['url=https://example.com?a=b']);
        $this->assertSame(['url' => 'https://example.com?a=b'], $parsed);
    }

    /**
     * The whole point of v0.2.11: a successful write has to leave a row in
     * tl_log, not only a line in a log channel that a Managed Edition drops.
     */
    public function testSuccessWritesToTheContaoSystemLog(): void
    {
        $systemLog = $this->createMock(SystemLog::class);
        $systemLog
            ->expects($this->once())
            ->method('write')
            ->with(
                'test:command {"id":1,"fields":{"title":"Neu"}}',
                'test:command',
                $this->anything(),
                ContaoContext::GENERAL,
            )
        ;

        $cmd = new ConcreteCommand();
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setSystemLog($systemLog);

        (new CommandTester($cmd))->execute(['--set' => ['title=Neu']]);
    }

    public function testSystemLogUsesTheOperatorAsUsername(): void
    {
        $captured = null;
        $systemLog = $this->createMock(SystemLog::class);
        $systemLog->method('write')->willReturnCallback(
            static function (string $text, string $func, string $username) use (&$captured): void {
                $captured = $username;
            }
        );

        $cmd = new ConcreteCommand();
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setSystemLog($systemLog);

        (new CommandTester($cmd))->execute(['--operator' => 'webwerkwien']);

        $this->assertSame('webwerkwien', $captured);
    }

    /**
     * The property is nullable so the hand-built commands in these tests keep
     * working. That only stays safe as long as the container really injects it,
     * which is what #[Required] does - a nullable property makes a lost
     * injection silent instead of fatal, so pin the attribute.
     */
    public function testSetSystemLogIsMarkedRequiredSoTheContainerInjectsIt(): void
    {
        $method = new \ReflectionMethod(AbstractWriteCommand::class, 'setSystemLog');
        $this->assertNotEmpty(
            $method->getAttributes(\Symfony\Contracts\Service\Attribute\Required::class),
            'setSystemLog() lost its #[Required] attribute - writes would stop reaching tl_log.'
        );
    }

    /**
     * @dataProvider fileCommands
     */
    public function testFileCommandsLogUnderTheFilesAction(string $class): void
    {
        $method = new \ReflectionMethod($class, 'systemLogAction');
        $method->setAccessible(true);

        $this->assertSame(
            ContaoContext::FILES,
            $method->invoke((new \ReflectionClass($class))->newInstanceWithoutConstructor())
        );
    }

    public static function fileCommands(): array
    {
        return [
            [FileWriteCommand::class],
            [FileMetaUpdateCommand::class],
            [FolderCreateCommand::class],
            [FileProcessCommand::class],
        ];
    }
}
