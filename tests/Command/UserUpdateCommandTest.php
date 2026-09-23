<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\UserUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * `admin` and `password` are on the deny list: an agent that can flip `admin`
 * on a backend user escalates itself to full control, and writing `password`
 * directly would bypass Contao's hashing.
 *
 * Since v1.1.0 a deny list rather than an allow list (Issue #71) — the allow
 * list guarded the same fields but shut out every column another bundle adds to
 * `tl_user` along with them. `id` is refused one level up, for every table, by
 * AbstractWriteCommand::refuseIdentityFields(); see IdentityFieldRefusalTest.
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
     * past the guard lands on the record lookup, which would need a real
     * container and database. The privilege-escalation guard is what matters
     * here, and it is a property of the list itself.
     *
     * Since v1.1.0 a DENY list (Issue #71). The allow list it replaced guarded
     * the same fields, but shut out every field added by another bundle with
     * them.
     */
    /**
     * @dataProvider escalationFields
     */
    public function testEscalationFieldsAreRefused(string $set): void
    {
        $out = $this->runCommand([$set . '=1']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('not allowed', $out['message']);
    }

    /**
     * `amg` and `cud` were missed by the first draft of the deny list and found
     * by the pre-release review. `amg` is not a preference: Contao's front-end
     * preview authenticates AS those member groups (`contao_user.amg`), so
     * setting it is impersonation. `cud` is the per-user permission table.
     *
     * The capitalised spellings are here because MySQL column names are not
     * case-sensitive — `--set Admin=1` addresses the same column.
     */
    public static function escalationFields(): array
    {
        return [
            'admin'               => ['admin'],
            'Admin'               => ['Admin'],
            'password'            => ['password'],
            'pwChange'            => ['pwChange'],
            'secret'              => ['secret'],
            'useTwoFactor'        => ['useTwoFactor'],
            'backupCodes'         => ['backupCodes'],
            'trustedTokenVersion' => ['trustedTokenVersion'],
            'session'             => ['session'],
            'amg'                 => ['amg'],
            'AMG'                 => ['AMG'],
        ];
    }

    /**
     * `cud` is the per-user create/update/delete permission table, and it was on
     * the deny list for one draft. It came off because in Contao 5.7 it is the
     * successor of `formp` (Version507\FieldPermissionMigration), and `formp`
     * stood on the old allow list — denying it would have made this release
     * stricter than the one it fixes. Pinned so the next reader does not
     * "obviously" put it back.
     */
    public function testCudStaysWritableBecauseFormpWas(): void
    {
        $this->assertNotContains('cud', UserUpdateCommand::DENIED_FIELDS);
        $this->assertNotContains('formp', UserUpdateCommand::DENIED_FIELDS);
        $this->assertNotContains('forms', UserUpdateCommand::DENIED_FIELDS);
        $this->assertNotContains('elements', UserUpdateCommand::DENIED_FIELDS);
        $this->assertNotContains('pagemounts', UserUpdateCommand::DENIED_FIELDS);
    }

    /**
     * The other half of the rule: ordinary administration and every column
     * another bundle adds to tl_user must NOT be on the list. Asserted against
     * the constant, because getting past the guard needs a database.
     */
    public function testOrdinaryAndForeignFieldsAreNotDenied(): void
    {
        $denied = UserUpdateCommand::DENIED_FIELDS;

        $this->assertIsArray($denied);
        $this->assertNotContains('email', $denied);
        $this->assertNotContains('name', $denied);
        $this->assertNotContains('groups', $denied);
        $this->assertNotContains('consho_vatId', $denied);
    }
}
