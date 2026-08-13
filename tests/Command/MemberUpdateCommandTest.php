<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * `password` is deliberately absent from the allow-list — writing it directly
 * would bypass Contao's hashing. `login` and `disable` are intentionally
 * allowed: the CLI operator is trusted to manage account state.
 */
class MemberUpdateCommandTest extends TestCase
{
    private function runCommand(array $set): array
    {
        $cmd = new MemberUpdateCommand($this->createMock(ContaoFramework::class));
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        $tester = new CommandTester($cmd);
        $tester->execute(['username' => 'some-member', '--set' => $set]);

        return json_decode($tester->getDisplay(), true);
    }

    public function testRejectsPasswordField(): void
    {
        $out = $this->runCommand(['password=hunter2']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('not allowed', $out['message']);
        $this->assertStringContainsString('password', $out['message']);
    }

    /**
     * Asserted against the constant rather than by running the command: getting
     * past the allow-list lands on the record lookup, which would need a real
     * container and database.
     *
     * @dataProvider profileFields
     */
    public function testAllowsProfileFields(string $field): void
    {
        $allowed = (new \ReflectionClass(MemberUpdateCommand::class))->getConstant('ALLOWED_FIELDS');

        $this->assertContains($field, $allowed);
    }

    public static function profileFields(): array
    {
        return [
            'firstname' => ['firstname'],
            'lastname'  => ['lastname'],
            'email'     => ['email'],
            'city'      => ['city'],
            'country'   => ['country'],
            // Account state is deliberately manageable from the CLI.
            'login'     => ['login'],
            'disable'   => ['disable'],
        ];
    }

    public function testCredentialFieldsStayOutOfTheAllowList(): void
    {
        $allowed = (new \ReflectionClass(MemberUpdateCommand::class))->getConstant('ALLOWED_FIELDS');

        $this->assertNotContains('password', $allowed, 'writing password directly bypasses Contao hashing');
        $this->assertNotContains('activation', $allowed);
        $this->assertNotContains('secret', $allowed);
    }

    public function testRejectsUnknownField(): void
    {
        $out = $this->runCommand(['totallyMadeUp=1']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('not allowed', $out['message']);
    }

    public function testRequiresAtLeastOneField(): void
    {
        $out = $this->runCommand([]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--set', $out['message']);
    }
}
