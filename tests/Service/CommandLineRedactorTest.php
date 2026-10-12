<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\CommandLineRedactor;

/**
 * A secret on the command line of contao:ai:run stays out of tl_log (v1.5.0).
 * Reported by the Consho session: the whole line was logged, a password included.
 */
class CommandLineRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lines(): iterable
    {
        yield 'set pair' => ['acme:shop:update 3 --set password=geheim --set title=Laden', 'acme:shop:update 3 --set password=*** --set title=Laden'];
        yield 'set pair with =' => ['acme:shop:update 3 --set=apiKey=abc123', 'acme:shop:update 3 --set=apiKey=***'];
        yield 'quoted pair' => ['acme:x --set "password=mit Leerzeichen" --set title=a', 'acme:x --set password=*** --set title=a'];
        yield 'option with =' => ['acme:x --api-key=abc --dry-run', 'acme:x --api-key=*** --dry-run'];
        yield 'option and value' => ['acme:x --token abc --dry-run', 'acme:x --token *** --dry-run'];
        yield 'quoted value' => ["acme:x --client-secret 'a b c' 7", 'acme:x --client-secret *** 7'];
        yield 'flag after a secret option is not a value' => ['acme:x --token --dry-run', 'acme:x --token --dry-run'];
        yield 'case' => ['acme:x --set Passwort=x', 'acme:x --set Passwort=***'];
        yield 'nothing secret is unchanged, spelling kept' => ['acme:x  --set "title=Mein Laden"   5', 'acme:x  --set "title=Mein Laden"   5'];
        yield 'a value that merely contains the word stays' => ['acme:x --set title=password-reset', 'acme:x --set title=password-reset'];
        // Fable review before v1.5.0: escapes as StringInput reads them.
        yield 'escaped space outside quotes' => ['acme:x --set password=ge\ S3CRET --set title=a', 'acme:x --set password=*** --set title=a'];
        yield 'escaped quote outside quotes' => ['acme:x --set password=a\"S3CRET', 'acme:x --set password=***'];
        yield 'escaped space after an option' => ['acme:x --token ab\ S3CRET 5', 'acme:x --token *** 5'];
        yield 'escaped quote inside single quotes' => ["acme:x --set 'password=S3CRET\\' x' --set title=a", 'acme:x --set password=*** --set title=a'];
        yield 'passwd, a word-pass and a key with a digit' => ['acme:x --set passwd=a --smtp-pass b --set 2fa_token=c', 'acme:x --set passwd=*** --smtp-pass *** --set 2fa_token=***'];
        yield 'passive is no secret' => ['acme:x --set passive=1', 'acme:x --set passive=1'];
        // v1.6.0: a line inside a line, as Symfony's console error listener logs contao:ai:run.
        yield 'inner line as option value' => ["contao:ai:run --command-line='acme:x --set name=a --set password=S3CRET'", "contao:ai:run --command-line='acme:x --set name=a --set password=***'"];
        yield 'inner line after the option' => ["contao:ai:run --command-line 'acme:x --set name=a --api-key S3CRET'", "contao:ai:run --command-line 'acme:x --set name=a --api-key ***'"];
        yield 'inner line whose first key reads secret is blanked whole' => ["contao:ai:run --command-line='acme:x --set password=S3CRET --set name=a'", 'contao:ai:run --command-line=acme:x --set password=***'];
        yield 'inner line with a quoted secret' => ["contao:ai:run --command-line='acme:x --set name=a --set \"password=S3 CRET\"'", "contao:ai:run --command-line='acme:x --set name=a --set password=***'"];
        yield 'a secret pair with a space is never split' => ['acme:x --set "password=S3 CRET"', 'acme:x --set password=***'];
        yield 'a text value with a secret pair inside' => ["acme:x --set 'note=mein password=S3CRET'", "acme:x --set 'note=mein password=***'"];
        yield 'an inner line with nothing secret is unchanged' => ["contao:ai:run --command-line='acme:x --set \"title=Mein Laden\"'", "contao:ai:run --command-line='acme:x --set \"title=Mein Laden\"'"];
    }

    /**
     * Review 2 before v1.5.0: an unclosed quote. StringInput takes it as a plain
     * character, a first version of this split read to the end of the line — and the
     * password after it stayed in the log.
     */
    public function testAnUnclosedQuoteIsSplitAsTheCommandReadsIt(): void
    {
        $this->assertSame('acme:x --set title="abc --set password=***', CommandLineRedactor::redact('acme:x --set title="abc --set password=S3CRET'));
        $this->assertSame("acme:x --set title='abc --set password=***", CommandLineRedactor::redact("acme:x --set title='abc --set password=S3CRET"));
    }

    /**
     * v1.6.0: what was blanked, as the command reads it — to strike from a message.
     */
    public function testRedactedNamesTheValuesItBlanked(): void
    {
        [$line, $values] = CommandLineRedactor::redacted(
            "contao:ai:run --command-line='acme:x --set name=a --set \"password=S3 CRET\" --api-key K3Y' --token=T0K",
        );

        $this->assertSame("contao:ai:run --command-line='acme:x --set name=a --set password=*** --api-key ***' --token=***", $line);
        $this->assertSame(['S3 CRET', 'K3Y', 'T0K'], $values);
        $this->assertSame(['x'], CommandLineRedactor::redacted('acme:x --set pin=x --set pin=x', ['pin'])[1], 'each once');

        // Fable review: an inner line whose first key reads secret is blanked whole, and
        // its values are still collected one by one — a message may quote just one.
        [$line, $values] = CommandLineRedactor::redacted("contao:ai:run --command-line='acme:x --set password=S3CRET --set name=a --pin P1N'", ['pin']);
        $this->assertSame('contao:ai:run --command-line=acme:x --set password=***', $line);
        $this->assertContains('S3CRET', $values);
        $this->assertContains('P1N', $values);
        $this->assertSame([], CommandLineRedactor::redacted('acme:x --set title=a')[1]);
        $this->assertNull(CommandLineRedactor::redacted('acme:x --set="sec\'\'ret=S3CRET"')[1], 'withheld: nothing says what to strike');
    }

    public function testWhereSymfonySplitsDifferentlyTheArgumentsAreWithheld(): void
    {
        // StringInput drops adjacent quote pairs inside a glued part: the command reads
        // `secret=S3CRET`, which this split does not see. Withheld, not guessed.
        $logged = CommandLineRedactor::redact('acme:x --set="sec\'\'ret=S3CRET"');

        $this->assertSame('acme:x ' . CommandLineRedactor::WITHHELD, $logged);
    }

    public function testAWithheldLineNeverKeepsAnOptionAsItsName(): void
    {
        $this->assertSame(CommandLineRedactor::WITHHELD, CommandLineRedactor::redact('--password=S3CRET acme:x --set="a\'\'b"'));
    }

    public function testAnAbsurdlyLongLineIsWithheldInsteadOfExhaustingMemory(): void
    {
        $logged = CommandLineRedactor::redact('acme:x ' . str_repeat('a ', 200000) . '--set password=S3CRET');

        $this->assertSame('acme:x ' . CommandLineRedactor::WITHHELD, $logged);
    }

    public function testAKeyWithASpaceIsJudgedByItsName(): void
    {
        $this->assertSame('acme:x --set pass phrase=***', CommandLineRedactor::redact('acme:x --set "pass phrase=S3CRET"'));
    }

    public function testASecretAfterALongQuotedValueIsStillBlanked(): void
    {
        // The regex version gave such a line back unchanged: a quoted value past 8 KB
        // exhausted PCRE's JIT stack, and preg_match_all failed without a word.
        $long = str_repeat('Lorem ipsum dolor ', 3000);
        $line = 'acme:x --set "text=' . $long . '" --set password=S3CRET';

        $logged = CommandLineRedactor::redact($line);

        $this->assertStringNotContainsString('S3CRET', $logged);
        $this->assertStringEndsWith('--set password=***', $logged);
        $this->assertStringContainsString($long, $logged);
    }

    #[DataProvider('lines')]
    public function testSecretsAreBlankedAndTheRestIsKept(string $line, string $logged): void
    {
        $this->assertSame($logged, CommandLineRedactor::redact($line));
    }

    public function testADeclaredNameIsBlankedThoughItDoesNotLookLikeOne(): void
    {
        $this->assertSame(
            'acme:x --pin *** --set iban=*** --set title=a',
            CommandLineRedactor::redact('acme:x --pin 4711 --set iban=AT61 --set title=a', ['--pin', 'iban']),
        );
    }

    public function testTheSecretItselfAppearsNowhereInTheResult(): void
    {
        $line = 'acme:x --set password=S3cr3t! --api-key "S3cr3t! two" --set=token=S3cr3t!';

        $this->assertStringNotContainsString('S3cr3t!', CommandLineRedactor::redact($line));
    }
}
