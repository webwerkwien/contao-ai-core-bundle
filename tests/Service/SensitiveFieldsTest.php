<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\SensitiveFields;

/**
 * No read command hands out a password hash, a two-factor secret or an API key (v1.8.1).
 *
 * Measured on a test installation before the fix: `record list tl_user --fields id,ai_api_key,password`
 * answered with the keys of three users in plain text (108 characters each) and their
 * password hashes.
 */
class SensitiveFieldsTest extends TestCase
{
    /**
     * As Contao 5.3 and the backend bundle declare them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function userFields(): array
    {
        return [
            'username'   => ['inputType' => 'text', 'sql' => "varchar(64) NOT NULL default ''"],
            'password'   => ['inputType' => 'password', 'sql' => "varchar(255) NOT NULL default ''"],
            'secret'     => ['eval' => ['doNotShow' => true, 'doNotCopy' => true], 'sql' => 'binary(128) NULL'],
            'ai_api_key' => ['inputType' => 'text', 'eval' => ['hideInput' => true, 'doNotShow' => true], 'sql' => "varchar(512) NOT NULL default ''"],
            'ai_model'   => ['inputType' => 'text', 'sql' => "varchar(128) NOT NULL default ''"],
        ];
    }

    public function testContaosDetailViewRuleDecides(): void
    {
        $this->assertSame(['password', 'secret', 'ai_api_key'], SensitiveFields::of($this->userFields()));
        $this->assertTrue(SensitiveFields::isSensitive(['eval' => ['hideInput' => true]]), 'hideInput alone');
        $this->assertFalse(SensitiveFields::isSensitive(['inputType' => 'text', 'eval' => ['doNotCopy' => true]]));
    }

    public function testSetValuesAreMaskedAndEmptyOnesStayEmpty(): void
    {
        $row = SensitiveFields::mask([
            'id'         => '3',
            'username'   => 'h.lewis',
            'password'   => '$2y$13$abcdefghijklmnopqrstuv',
            'secret'     => null,
            'ai_api_key' => '',
            'ai_model'   => 'claude',
        ], $this->userFields());

        $this->assertSame('********', $row['password']);
        $this->assertNull($row['secret'], 'not set stays not set');
        $this->assertSame('', $row['ai_api_key'], 'an empty key says "none", which is worth knowing');
        $this->assertSame('h.lewis', $row['username']);
        $this->assertSame('claude', $row['ai_model']);
    }

    public function testTheSpellingOfTheColumnDoesNotMatter(): void
    {
        // MySQL resolves column names case-insensitively; so does the mask.
        $row = SensitiveFields::mask(['AI_API_KEY' => 'sk-ant-123456789'], $this->userFields());

        $this->assertSame('********', $row['AI_API_KEY']);
    }

    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function guesses(): iterable
    {
        yield 'filter'        => ['buildWhere', [['ai_api_key=sk-ant-123'], ['id', 'ai_api_key']]];
        yield 'filter-prefix' => ['buildWhere', [[], ['id', 'password'], ['password=$2y$']]];
        yield 'order'         => ['buildOrderClause', ['secret DESC', ['id', 'secret']]];
        yield 'other case'    => ['buildWhere', [['Ai_Api_Key=sk'], ['id', 'Ai_Api_Key']]];
    }

    /**
     * A filter on a sensitive field would answer whether a guessed key is right.
     *
     * @dataProvider guesses
     *
     * @param list<mixed> $arguments
     */
    public function testRecordListRefusesASensitiveFieldAsFilterOrSortKey(string $method, array $arguments): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');

        $command = new RecordListCommand($this->createMock(ContaoFramework::class), $connection);
        (new \ReflectionProperty($command, 'sensitive'))->setValue($command, array_map('strtolower', SensitiveFields::of($this->userFields())));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be filtered or sorted by');

        (new \ReflectionMethod($command, $method))->invoke($command, ...$arguments);
    }

    public function testRecordListStillFiltersOrdinaryFields(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');

        $command = new RecordListCommand($this->createMock(ContaoFramework::class), $connection);
        (new \ReflectionProperty($command, 'sensitive'))->setValue($command, ['password', 'secret', 'ai_api_key']);

        [$where] = (new \ReflectionMethod($command, 'buildWhere'))->invoke($command, ['username=h.lewis'], ['id', 'username']);

        $this->assertSame('`username` = :f0', $where);
    }
}
