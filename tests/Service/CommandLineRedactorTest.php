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
