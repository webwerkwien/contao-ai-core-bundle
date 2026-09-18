<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberPasswordCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * contao:member:create and contao:member:password (v0.26.0): everything that is decided
 * before the database — the password never as an option, Contao's two checks, one
 * line from stdin with its spaces kept.
 *
 * Until v0.26.0 `contao:member:create` did not exist although the CLI offered it
 * (regression run before 1.0, 2026-09-18), and no command could set a member password.
 * The write itself — hash, save callbacks, version — needs a real installation and was
 * verified on c5 (5.3.51, 5.7.13, 6.0.0).
 */
class MemberPasswordCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_member']['fields']['password'] = ['inputType' => 'password', 'eval' => ['minlength' => 8]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_member']);
    }

    private function hasherFactory(): PasswordHasherFactoryInterface
    {
        return $this->createMock(PasswordHasherFactoryInterface::class);
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string>|null    $stdin
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function exec(Command $command, array $args, ?array $stdin = null): array
    {
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));

        $tester = new CommandTester($command);
        if (null !== $stdin) {
            $tester->setInputs($stdin);
        }
        $code = $tester->execute($args);

        return [$code, json_decode($tester->getDisplay(), true)];
    }

    private function create(array $extra = [], ?array $stdin = null): array
    {
        return $this->exec(
            new MemberCreateCommand($this->createMock(ContaoFramework::class), $this->hasherFactory()),
            ['--username' => 'anna', '--firstname' => 'Anna', '--lastname' => 'Muster', '--email' => 'anna@example.org'] + $extra,
            $stdin,
        );
    }

    public function testThePasswordIsNeverAnOption(): void
    {
        $this->assertFalse((new MemberCreateCommand($this->createMock(ContaoFramework::class), $this->hasherFactory()))
            ->getDefinition()->hasOption('password'));
        $this->assertFalse((new MemberPasswordCommand($this->createMock(ContaoFramework::class), $this->hasherFactory()))
            ->getDefinition()->hasOption('password'));
    }

    public function testPasswordAsASetFieldIsRefused(): void
    {
        [$code, $out] = $this->create(['--password-stdin' => true, '--set' => ['password=hunter2hunter2']], ['x']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not allowed: password', $out['message']);
    }

    public function testWithoutPasswordStdinNothingIsWritten(): void
    {
        [$code, $out] = $this->create();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--password-stdin is required', $out['message']);
    }

    /**
     * An empty line, not an absent stream: without inputs CommandTester would leave the
     * real STDIN in place, and on a terminal the test would wait for it.
     */
    public function testAnEmptyStdinIsRefused(): void
    {
        [$code, $out] = $this->create(['--password-stdin' => true], ['']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No password on standard input', $out['message']);
    }

    public function testAMissingOptionIsNamed(): void
    {
        [$code, $out] = $this->exec(
            new MemberCreateCommand($this->createMock(ContaoFramework::class), $this->hasherFactory()),
            ['--username' => 'anna', '--firstname' => 'Anna', '--lastname' => 'Muster', '--password-stdin' => true],
            ['long-enough-1'],
        );

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--email is required', $out['message']);
    }

    public function testTooShortAsInContaosPasswordField(): void
    {
        [$code, $out] = $this->create(['--password-stdin' => true], ['kurz']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('at least 8 characters', $out['message']);
    }

    public function testThePasswordMayNotBeTheUsername(): void
    {
        [$code, $out] = $this->exec(
            new MemberPasswordCommand($this->createMock(ContaoFramework::class), $this->hasherFactory()),
            ['username' => 'anna-muster', '--password-stdin' => true],
            ['anna-muster'],
        );

        $this->assertSame(1, $code);
        $this->assertStringContainsString('same as the username', $out['message']);
    }

    public function testMemberPasswordTakesNoSetFields(): void
    {
        [$code, $out] = $this->exec(
            new MemberPasswordCommand($this->createMock(ContaoFramework::class), $this->hasherFactory()),
            ['username' => 'anna', '--password-stdin' => true, '--set' => ['email=x@example.org']],
            ['long-enough-1'],
        );

        $this->assertSame(1, $code);
        $this->assertStringContainsString('takes no --set fields', $out['message']);
    }

    /**
     * Only the line break goes: a password may begin or end with a space, and trimming
     * would store a different secret than the one piped in.
     */
    public function testSpacesAtTheEdgesAreKept(): void
    {
        $command = new MemberPasswordCommand($this->createMock(ContaoFramework::class), $this->hasherFactory());
        $read    = new \ReflectionMethod($command, 'readPasswordFromStdin');

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setStream($this->stream(" mit Leerzeichen \r\n"));

        $error = null;
        $this->assertSame(' mit Leerzeichen ', $read->invokeArgs($command, [$input, &$error]));
    }

    public function testTheFrontEndUsersHasherIsUsed(): void
    {
        $hasher = $this->createMock(\Symfony\Component\PasswordHasher\PasswordHasherInterface::class);
        $hasher->method('hash')->with('geheim-geheim')->willReturn('$argon2id$…');

        $factory = $this->createMock(PasswordHasherFactoryInterface::class);
        $factory->expects($this->once())->method('getPasswordHasher')->with(\Contao\FrontendUser::class)->willReturn($hasher);

        $command = new MemberPasswordCommand($this->createMock(ContaoFramework::class), $factory);

        $this->assertSame('$argon2id$…', (new \ReflectionMethod($command, 'hashMemberPassword'))->invoke($command, 'geheim-geheim'));
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
