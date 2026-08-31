<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsletterSendGuard;

/**
 * The guard that keeps a newsletter from being marked sent without being sent.
 *
 * 🎯 The decision of 2026-08-31 was "no send command". That decision is only
 * worth something if the obvious substitute is closed too: `--set sent=1` does
 * not send a newsletter, it publishes it in the front end and labels it sent.
 * A boundary an agent can step around is a detour sign, not a boundary.
 *
 * The wiring assertions matter as much as the logic ones. The guard is three
 * lines of array work — what actually goes wrong is a create or update command
 * that never calls it.
 */
class NewsletterSendGuardTest extends TestCase
{
    public function testOrdinaryFieldsPassThrough(): void
    {
        $this->assertNull(NewsletterSendGuard::refuse([]));
        $this->assertNull(NewsletterSendGuard::refuse(['subject' => 'Juni-Ausgabe', 'text' => 'Hallo']));
    }

    public function testSentIsRefused(): void
    {
        $message = NewsletterSendGuard::refuse(['sent' => '1']);

        $this->assertNotNull($message);
        $this->assertStringContainsString('tl_newsletter.sent', $message);
    }

    /**
     * `sent=0` is refused as well. Resetting the flag is not harmless: it pulls
     * a newsletter back out of the front end archive, and it is the same field
     * only Contao's send routine may own.
     */
    public function testSentIsRefusedEvenWhenClearingIt(): void
    {
        $this->assertNotNull(NewsletterSendGuard::refuse(['sent' => '0']));
        $this->assertNotNull(NewsletterSendGuard::refuse(['sent' => '']));
    }

    public function testDateIsRefused(): void
    {
        $message = NewsletterSendGuard::refuse(['date' => '1756598400']);

        $this->assertNotNull($message);
        $this->assertStringContainsString('tl_newsletter.date', $message);
    }

    public function testBothAreNamedWhenBothAreGiven(): void
    {
        $message = NewsletterSendGuard::refuse(['sent' => '1', 'date' => '1756598400', 'subject' => 'x']);

        $this->assertNotNull($message);
        $this->assertStringContainsString('sent', $message);
        $this->assertStringContainsString('date', $message);
    }

    /**
     * The message has to say why, not just no. An agent that reads "refused"
     * looks for another route; one that reads what setting the flag actually
     * does has no reason to.
     */
    public function testTheMessageExplainsWhatSettingTheFlagWouldDo(): void
    {
        $message = (string) NewsletterSendGuard::refuse(['sent' => '1']);

        $this->assertStringContainsString('no recipient ever got', $message);
        $this->assertStringContainsString('back end', $message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedCommands(): iterable
    {
        yield 'create' => ['NewsletterCreateCommand.php'];
        yield 'update' => ['NewsletterUpdateCommand.php'];
    }

    /**
     * @dataProvider guardedCommands
     */
    public function testEveryWritePathToTlNewsletterCallsTheGuard(string $basename): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $basename);

        $this->assertStringContainsString(
            'NewsletterSendGuard::refuse(',
            $source,
            $basename . ' writes tl_newsletter without consulting NewsletterSendGuard, so '
            . '--set sent=1 reaches the column through it.',
        );
    }

    /**
     * No send command, by decision. If one ever appears, this fails and whoever
     * added it has to revisit the reasoning in the project file rather than
     * discover it afterwards.
     */
    public function testNoSendCommandExists(): void
    {
        $files = glob(__DIR__ . '/../../src/Command/*Command.php');
        $this->assertIsArray($files);

        $sendCommands = array_values(array_filter(
            array_map('basename', $files),
            static fn (string $name): bool => (bool) preg_match('/^Newsletter.*Send.*Command\.php$/', $name),
        ));

        $this->assertSame([], $sendCommands, 'Sending stays with a person in the Contao back end.');
    }
}
