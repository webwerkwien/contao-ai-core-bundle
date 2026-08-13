<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\UserUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * `admin` and `password` are deliberately absent from the allow-list: an agent
 * that can flip `admin` on a backend user escalates itself to full control, and
 * writing `password` directly would bypass Contao's hashing.
 */
class UserUpdateCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        $cmd = new UserUpdateCommand($this->createMock(ContaoFramework::class));
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return new CommandTester($cmd);
    }

    private function runCommand(array $set): array
    {
        $tester = $this->tester();
        $tester->execute(['username' => 'some-user', '--set' => $set]);

        return json_decode($tester->getDisplay(), true);
    }

    public function testRejectsAdminField(): void
    {
        $out = $this->runCommand(['admin=1']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('not allowed', $out['message']);
        $this->assertStringContainsString('admin', $out['message']);
    }

    public function testRejectsPasswordField(): void
    {
        $out = $this->runCommand(['password=hunter2']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('not allowed', $out['message']);
        $this->assertStringContainsString('password', $out['message']);
    }

    /**
     * A rejected field must be reported even when mixed in with legitimate ones,
     * so a single disallowed entry cannot ride along with a valid update.
     */
    public function testRejectsDisallowedFieldMixedWithAllowedOnes(): void
    {
        $out = $this->runCommand(['email=new@example.com', 'admin=1']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('admin', $out['message']);
    }

    public function testRequiresAtLeastOneField(): void
    {
        $out = $this->runCommand([]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--set', $out['message']);
    }

    /**
     * Asserted against the constant rather than by running the command: getting
     * past the allow-list lands on the record lookup, which would need a real
     * container and database. The privilege-escalation guard is what matters
     * here, and it is a property of the list itself.
     */
    public function testAllowListShapeGuardsPrivilegeEscalation(): void
    {
        $allowed = (new \ReflectionClass(UserUpdateCommand::class))->getConstant('ALLOWED_FIELDS');

        $this->assertIsArray($allowed);
        $this->assertNotContains('admin', $allowed, 'admin would let an agent escalate to full control');
        $this->assertNotContains('password', $allowed, 'writing password directly bypasses Contao hashing');
        $this->assertNotContains('pwChange', $allowed);
        $this->assertNotContains('secret', $allowed, 'two-factor secret must never be settable');
        $this->assertNotContains('useTwoFactor', $allowed);

        // Ordinary profile administration stays available.
        $this->assertContains('email', $allowed);
        $this->assertContains('name', $allowed);
        $this->assertContains('groups', $allowed);
    }
}
