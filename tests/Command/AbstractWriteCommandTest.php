<?php

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
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
}
