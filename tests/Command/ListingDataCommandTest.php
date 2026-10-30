<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ListingDataCommand;

/**
 * `contao:listing:data <id>` runs the query a listing module runs (v1.9.0), so that
 * contao-ai-cli no longer parses doctrine:query:sql's ASCII table for it.
 */
class ListingDataCommandTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_member']);
    }

    /**
     * @param array<string, mixed>|false     $module
     * @param list<array<string, mixed>>     $rows
     * @param array<string, mixed>           $input
     *
     * @return array<string, mixed>
     */
    private function listing(array|false $module, array $rows = [], array $input = [], ?InsertTagParser $insertTags = null): array
    {
        // What loadDataContainer() leaves behind on a real installation; without a DCA the
        // masking would look the table up through the (absent) container.
        $GLOBALS['TL_DCA']['tl_member']['fields'] ??= ['firstname' => [], 'lastname' => []];

        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('fetchAssociative')->willReturn($module);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use ($rows): int {
            $this->queries[] = $sql;

            return \count($rows) + 40;
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql) use ($rows): array {
            $this->queries[] = $sql;

            return $rows;
        });

        $tester = new CommandTester(new ListingDataCommand($this->createMock(ContaoFramework::class), $connection, $insertTags));
        $tester->execute(['id' => '7'] + $input);

        return json_decode($tester->getDisplay(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function module(array $overrides = []): array
    {
        return array_replace([
            'id' => 7, 'type' => 'listing', 'list_table' => 'tl_member',
            'list_fields' => 'firstname, lastname,password', 'list_where' => 'disable=0', 'list_sort' => 'lastname',
        ], $overrides);
    }

    public function testItRunsTheModulesOwnConditionAndSort(): void
    {
        $out = $this->listing(self::module(), [['id' => 1, 'firstname' => 'Jörg', 'lastname' => 'Müller', 'password' => '$2y$hash']]);

        self::assertSame('ok', $out['status'], (string) json_encode($out));
        self::assertSame(41, $out['total']);
        self::assertSame(['id', 'firstname', 'lastname', 'password'], $out['columns']);
        self::assertSame('Müller', $out['results'][0]['lastname']);
        self::assertSame('SELECT COUNT(*) FROM `tl_member` t1 WHERE (disable=0)', $this->queries[0]);
        self::assertSame(
            'SELECT t1.`id`, t1.`firstname`, t1.`lastname`, t1.`password` FROM `tl_member` t1 WHERE (disable=0) ORDER BY lastname LIMIT 100 OFFSET 0',
            $this->queries[1],
        );
    }

    /**
     * The front end leaves password fields out; the answer masks them, and every other
     * sensitive field, as all read commands do since v1.8.1.
     */
    public function testSensitiveFieldsAreMasked(): void
    {
        $GLOBALS['TL_DCA']['tl_member']['fields']['password'] = ['inputType' => 'password'];

        $out = $this->listing(self::module(), [
            ['id' => 1, 'firstname' => 'A', 'lastname' => 'B', 'password' => '$2y$hash'],
            ['id' => 2, 'firstname' => 'C', 'lastname' => 'D', 'password' => '$2y$other'],
        ]);

        self::assertSame(['********', '********'], array_column($out['results'], 'password'), 'every row, not the first');
    }

    public function testADateSortIsCastAsTheModuleCastsIt(): void
    {
        $GLOBALS['TL_DCA']['tl_member']['fields']['dateAdded'] = ['eval' => ['rgxp' => 'datim']];

        $this->listing(self::module(['list_sort' => 'dateAdded', 'list_where' => '']));

        self::assertStringEndsWith('FROM `tl_member` t1 ORDER BY CAST(`dateAdded` AS SIGNED) LIMIT 100 OFFSET 0', $this->queries[1]);
    }

    public function testPagingIsBounded(): void
    {
        $this->listing(self::module(), [], ['--limit' => '5000', '--offset' => '20']);

        self::assertStringEndsWith('LIMIT 1000 OFFSET 20', $this->queries[1]);
    }

    public function testInsertTagsInTheConditionAreReplacedFirst(): void
    {
        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->expects($this->once())->method('replaceInline')->with('pid={{env::page_id}}')->willReturn('pid=3');

        $this->listing(self::module(['list_where' => 'pid={{env::page_id}}']), [], [], $insertTags);

        self::assertSame('SELECT COUNT(*) FROM `tl_member` t1 WHERE (pid=3)', $this->queries[0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|false, string}>
     */
    public static function refusals(): iterable
    {
        yield 'missing'       => [false, 'Module not found: 7'];
        yield 'not a listing' => [['id' => 7, 'type' => 'navigation'], 'not a listing module'];
        yield 'no table'      => [self::module(['list_table' => '']), 'no list_table or list_fields'];
        yield 'odd table'     => [self::module(['list_table' => 'tl_member; DROP TABLE tl_user']), 'not a table or field name'];
        yield 'odd field'     => [self::module(['list_fields' => 'firstname, (SELECT 1)']), 'not a table or field name'];
    }

    /**
     * @dataProvider refusals
     *
     * @param array<string, mixed>|false $module
     */
    public function testWhatCannotBeRunIsRefusedWithoutAQuery(array|false $module, string $message): void
    {
        $out = $this->listing($module);

        self::assertSame('error', $out['status']);
        self::assertStringContainsString($message, $out['message']);
        self::assertSame([], $this->queries);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function guesses(): iterable
    {
        yield 'masked column in the condition' => [['list_where' => "PASSWORD LIKE '\$2y\$13\$a%'"], 'whose value is masked'];
        yield 'masked column in the sort'      => [['list_sort' => 't1.password'], 'whose value is masked'];
        yield 'subquery into another table'    => [['list_where' => "disable=0 AND (SELECT ai_api_key FROM tl_user WHERE id=1) LIKE 'sk-a%'"], 'subquery'];
    }

    /**
     * The condition runs before the mask; the count would answer a guess (v1.14.0).
     *
     * @dataProvider guesses
     *
     * @param array<string, string> $overrides
     */
    public function testAConditionThatCouldTestAMaskedValueIsNotRun(array $overrides, string $message): void
    {
        $GLOBALS['TL_DCA']['tl_member']['fields'] = ['firstname' => [], 'lastname' => [], 'password' => ['inputType' => 'password']];

        $out = $this->listing(self::module($overrides));

        self::assertSame('error', $out['status']);
        self::assertStringContainsString($message, $out['message']);
        self::assertSame([], $this->queries);
    }
}
