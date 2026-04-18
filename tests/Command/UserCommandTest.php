<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoCliBridgeBundle\Command\UserUpdateCommand;
use Webwerkwien\ContaoCliBridgeBundle\Command\UserDeleteCommand;

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
