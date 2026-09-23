<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UserUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * `password` is on the deny list — writing it directly would bypass Contao's
 * hashing. `login` and `disable` are deliberately NOT on it: the CLI operator
 * is trusted to manage account state.
 *
 * Since v1.1.0 the guard is a deny list. The allow list it replaced also shut
 * out every field added by another bundle (Issue #71).
 *
 * ⚠️ **What these tests can and cannot show.** That credentials are refused is
 * asserted through the command — the deny check runs before the record lookup,
 * so it needs no database. That a foreign field *reaches the column check* is
 * not asserted here: getting past the deny list lands on
 * `MemberModel::findByUsername()`, which needs a booted framework. It is
 * covered generically in UnknownFieldRefusalTest, and live on c5 in Issue #71.
 * Below, a foreign field is only shown to be absent from the deny list.
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
     * past the guard lands on the record lookup, which would need a real
     * container and database.
     *
     * @dataProvider passableFields
     */
    public function testProfileAndForeignFieldsAreNotDenied(string $field): void
    {
        $this->assertNotContains($field, MemberUpdateCommand::DENIED_FIELDS);
    }

    public static function passableFields(): array
    {
        return [
            'firstname'               => ['firstname'],
            'lastname'                => ['lastname'],
            'email'                   => ['email'],
            'city'                    => ['city'],
            'country'                 => ['country'],
            // Account state is deliberately manageable from the CLI.
            'login'                   => ['login'],
            'disable'                 => ['disable'],
            // 🎯 Issue #71: the allow list refused these outright although
            // `contao:dca:schema tl_member` lists them as writable columns.
            'consho_shippingStreet'   => ['consho_shippingStreet'],
            'consho_vatId'            => ['consho_vatId'],
        ];
    }

    /**
     * Run through the command, not asserted against the constant: what matters
     * is that the call is refused, not that a string sits in an array. The deny
     * check runs before the record lookup, so this needs no database.
     *
     * @dataProvider credentialFields
     */
    public function testCredentialAndTwoFactorFieldsAreDenied(string $set): void
    {
        $out = $this->runCommand([$set . '=x']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('not allowed', $out['message']);
    }

    /**
     * The capitalised spellings are in here on purpose: MySQL column names are
     * not case-sensitive, so `--set Password=…` addresses the same column. The
     * first draft of the deny list compared raw keys and let them through.
     */
    public static function credentialFields(): array
    {
        return [
            'password'            => ['password'],
            'Password'            => ['Password'],
            // parseSetOptions() does not trim, so " password=…" arrives with the
            // space attached; deniedAmong() trims before comparing.
            'leading space'       => [' password'],
            'secret'              => ['secret'],
            'useTwoFactor'        => ['useTwoFactor'],
            'usetwofactor'        => ['usetwofactor'],
            'backupCodes'         => ['backupCodes'],
            'trustedTokenVersion' => ['trustedTokenVersion'],
            'session'             => ['session'],
        ];
    }

    /**
     * Both deny lists stay SHORT on purpose. A list that grows by "no use case
     * comes to mind" turns back into the allow list this replaced — which is
     * how Issue #71 happened. Every entry has to name what it protects.
     *
     * The caps are deliberately close to the current counts (6 and 10), so the
     * next addition has to touch this test and say why.
     */
    public function testTheDenyListsStayNarrow(): void
    {
        $this->assertLessThanOrEqual(8, \count(MemberUpdateCommand::DENIED_FIELDS),
            'a growing deny list is an allow list in disguise — see Issue #71');
        $this->assertLessThanOrEqual(10, \count(UserUpdateCommand::DENIED_FIELDS),
            'a growing deny list is an allow list in disguise — see Issue #71');
    }

    /**
     * Lower case throughout, because deniedAmong() lower-cases the caller's key
     * before comparing. An entry spelled `useTwoFactor` would never match and
     * the guard would be silently open — which is exactly what happened between
     * the first draft of this change and the review.
     */
    public function testTheDenyListsAreLowerCase(): void
    {
        foreach ([...MemberUpdateCommand::DENIED_FIELDS, ...UserUpdateCommand::DENIED_FIELDS] as $field) {
            $this->assertSame(strtolower($field), $field, "deny list entry '$field' must be lower case");
        }
    }

    public function testRequiresAtLeastOneField(): void
    {
        $out = $this->runCommand([]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--set', $out['message']);
    }
}
