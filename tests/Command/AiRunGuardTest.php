<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\AiRunGuard;

/**
 * `contao:ai:run` reaches the `contao:` namespace and nothing else.
 *
 * 🎯 **Without the restriction it is a remote console, and the first thing it
 * hands back is `doctrine:query:sql`.** This bundle spent two days moving reads
 * and writes off raw SQL onto commands that validate against the DCA, version
 * and log. A generic runner that reaches `doctrine:query:sql` puts every one of
 * those guarantees back on the honour system — through the very tool built to
 * end that.
 *
 * ⚠️ Not a security boundary, and the tests should not be read as claiming one:
 * whoever calls this has shell access and can run anything. It bounds what
 * *this tool* does on its own, which is the smaller and more useful claim.
 */
class AiRunGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed(): iterable
    {
        yield 'own command'       => ['contao:page:tree'];
        yield 'contao core'       => ['contao:migrate'];
        yield 'plugin-style name' => ['contao:ww-buchung:sync'];
    }

    /**
     * @dataProvider allowed
     */
    public function testTheContaoNamespaceIsReachable(string $command): void
    {
        $this->assertTrue(AiRunGuard::isAllowed($command));
    }

    /**
     * A declared contract opens the door as well (2026-09-01).
     *
     * A parallel session measured 22 namespaces on a live site: `cookiebar:`
     * is a published Contao extension using its own product name, and
     * Symfony's docs recommend `app:` for application commands. A prefix of
     * one's own is the convention, and `contao:` would be the one wrong
     * choice — it claims someone else's property.
     *
     * 🎯 The prefix says *who wrote* the command; this guard was reading it as
     * *whether it may run*. Two different questions of one source, and a
     * correctly named extension command fell out — not because it was
     * dangerous, but because the name cannot carry what was demanded of it.
     *
     * The declaration comes from the author, which is the answer a prefix
     * never had.
     */
    public function testADeclaredContractMakesACommandReachable(): void
    {
        $this->assertFalse(AiRunGuard::isAllowed('ww:gutschein:import'));
        $this->assertTrue(AiRunGuard::isAllowed('ww:gutschein:import', true));
    }

    public function testTheDefaultStaysClosed(): void
    {
        /* Nothing is reachable by accident: the second argument has to be
           passed, and only a real declaration sets it. */
        $this->assertFalse(AiRunGuard::isAllowed('doctrine:query:sql'));
    }

    public function testTheRefusalNamesBothWaysIn(): void
    {
        $message = AiRunGuard::refusal('cookiebar:something');

        $this->assertStringContainsString('contao:', $message);
        $this->assertStringContainsString('AiContract', $message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'the one that matters' => ['doctrine:query:sql'];
        yield 'framework'            => ['cache:clear'];
        yield 'messenger'            => ['messenger:consume'];
        yield 'debug'                => ['debug:container'];
        yield 'empty'                => [''];
        yield 'near miss'            => ['contao-something:run'];
    }

    /**
     * @dataProvider refused
     */
    public function testEverythingElseIsOutOfReach(string $command): void
    {
        $this->assertFalse(AiRunGuard::isAllowed($command));
    }

    /**
     * The refusal has to say why, and name the case that motivated it — a
     * reader who only sees "not allowed" goes looking for a way around.
     */
    public function testTheRefusalExplainsItself(): void
    {
        $message = AiRunGuard::refusal('doctrine:query:sql');

        $this->assertStringContainsString('doctrine:query:sql', $message);
        $this->assertStringContainsString('honour system', $message);
    }

    /**
     * Both commands consult the guard. `ai:commands --name=` describes a
     * command; describing what cannot be run would be an odd half-permission,
     * and worse, it would advertise the framework namespace as available.
     */
    public function testBothEntryPointsConsultTheGuard(): void
    {
        foreach (['AiRunCommand.php', 'AiCommandsCommand.php'] as $file) {
            $source = (string) file_get_contents(__DIR__.'/../../src/Command/'.$file);

            $this->assertStringContainsString(
                'AiRunGuard::isAllowed(',
                $source,
                $file.' does not consult AiRunGuard, so it reaches outside the contao: namespace.',
            );
        }
    }

    /**
     * The log entry is written before the target runs, not after.
     *
     * A log written afterwards records only the runs that went well, which is
     * the opposite of what an audit trail is for. Proven live on 2026-09-01: a
     * passthrough that failed on a missing argument still left its entry.
     */
    public function testTheInvocationIsRecordedBeforeItRuns(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Command/AiRunCommand.php');

        $record = strpos($source, '$this->recordInvocation(');
        $run    = strpos($source, '$application->doRun(');

        $this->assertIsInt($record);
        $this->assertIsInt($run);
        $this->assertLessThan(
            $run,
            $record,
            'The log entry must be written before the target runs, or a command that '
            . 'crashes leaves no record that it was started.',
        );
    }
}
