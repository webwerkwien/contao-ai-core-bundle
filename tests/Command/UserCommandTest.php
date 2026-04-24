<?php

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\UserUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UserDeleteCommand;

class UserCommandTest extends TestCase
{
    public function testUserUpdateCommandName(): void
    {
        $this->assertSame('contao:user:update', UserUpdateCommand::getDefaultName());
    }

    public function testUserDeleteCommandName(): void
    {
        $this->assertSame('contao:user:delete', UserDeleteCommand::getDefaultName());
    }
}
