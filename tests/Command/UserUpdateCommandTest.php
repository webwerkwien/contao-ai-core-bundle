<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserUpdateCommandTest extends TestCase
{
    public function testRejectsAdminField(): void
    {
        // Build command with mock framework + mock VersionManager
        // ...  (mock ContaoFramework, UserModel, VersionManager)
        // Execute with --set admin=1
        // Assert output contains 'error' and 'not allowed'
        $this->markTestIncomplete('Implement with mock ContaoFramework');
    }

    public function testRejectsPasswordField(): void
    {
        // --set password=abc should return error
        $this->markTestIncomplete('Implement with mock ContaoFramework');
    }
}
