<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;

/**
 * A copy is marked in the operator's back-end language, as the back end marks it in
 * the editor's. Without a language of its own the copier keeps the mark that is loaded.
 */
class OperatorLanguageTest extends TestCase
{
    /**
     * @return iterable<string, array{object|null, string|null}>
     */
    public static function users(): iterable
    {
        yield 'a user with a language' => [(object) ['id' => 5, 'language' => 'de'], 'de'];
        yield 'a user without one' => [(object) ['id' => 5, 'language' => ''], null];
        yield 'a language not yet set' => [(object) ['id' => 5, 'language' => null], null];
        yield 'no such user' => [null, null];
    }

    /**
     * @dataProvider users
     */
    public function testTheOperatorsLanguage(?object $user, ?string $expected): void
    {
        self::assertSame($expected, (new OperatorLanguageProbe($user))->language());
    }

    public function testTheAuthorComesFromTheSameUser(): void
    {
        self::assertSame(5, (new OperatorLanguageProbe((object) ['id' => '5', 'language' => 'de']))->author());
        self::assertSame(1, (new OperatorLanguageProbe(null))->author());
    }
}

final class OperatorLanguageProbe extends AbstractWriteCommand
{
    public function __construct(private readonly ?object $user)
    {
        parent::__construct('test:probe:operator');
    }

    public function language(): ?string
    {
        return $this->resolveOperatorLanguage();
    }

    public function author(): int
    {
        return $this->resolveAuthorId();
    }

    protected function operatorUser(): ?object
    {
        return $this->user;
    }

    protected function doExecute(array $fields): int
    {
        return Command::SUCCESS;
    }
}
