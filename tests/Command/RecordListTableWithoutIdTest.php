<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;

/**
 * Not every Contao table has an `id` and a `tstamp`.
 *
 * The command assumed both. `$allowedColumns` merged them in by decree, with a
 * comment claiming they "always exist in tl_* tables", and the `--order` option
 * carried the literal default `id DESC`. Measured against a stock 5.7 install
 * that is wrong four times over: tl_opt_in_related, tl_newsletter_deny_list,
 * tl_search_index and tl_search_term have no `tstamp`, and tl_search_index —
 * a pure join table of pid, termId and relevance — has no `id` either.
 *
 * The failures were not graceful. A missing `tstamp` reached the SELECT and
 * came back as an uncaught SQLSTATE[42S22] with exit 255. A missing `id` left
 * the default column list empty, which rendered as `SELECT  FROM …` — an SQL
 * syntax error, again exit 255. Neither said anything a caller could use.
 *
 * Three separate assumptions, one root: the command treated the shape of the
 * common tables as the shape of all of them.
 */
class RecordListTableWithoutIdTest extends TestCase
{
    private function command(): RecordListCommand
    {
        $connection = $this->createMock(Connection::class);
        // Without this the mock returns '' and every clause reads " DESC",
        // which fails the assertions for a reason that has nothing to do with
        // the code under test.
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`'
        );

        return new RecordListCommand($this->createMock(ContaoFramework::class), $connection);
    }

    /**
     * @param list<string> $args
     */
    private function call(string $method, array $args): mixed
    {
        return (new \ReflectionMethod(RecordListCommand::class, $method))
            ->invokeArgs($this->command(), $args);
    }

    // --- the default sort order ---

    public function testTheDefaultOrderIsIdWhereThereIsAnId(): void
    {
        $clause = $this->call('buildOrderClause', ['', ['id', 'tstamp', 'title']]);

        $this->assertStringContainsString('id', $clause);
        $this->assertStringContainsString('DESC', $clause);
    }

    public function testATableWithoutAnIdSortsByItsFirstRealColumnInstead(): void
    {
        $clause = $this->call('buildOrderClause', ['', ['pid', 'termId', 'relevance']]);

        $this->assertStringContainsString('pid', $clause);
        $this->assertStringNotContainsString('`id`', $clause, 'Sorting by a column that is not there is the bug.');
    }

    public function testAnExplicitOrderStillWinsOverTheFallback(): void
    {
        $clause = $this->call('buildOrderClause', ['relevance DESC', ['pid', 'termId', 'relevance']]);

        $this->assertStringContainsString('relevance', $clause);
    }

    public function testATableWithNoColumnsAtAllIsReportedRatherThanQueried(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->call('buildOrderClause', ['', []]);
    }

    // --- the default column list ---

    public function testTheColumnListNeverComesBackEmpty(): void
    {
        // Neither id nor tstamp nor a label field — tl_search_index's situation.
        // An empty list became `SELECT  FROM …`.
        $columns = $this->call('defaultColumns', ['tl_search_index', ['pid', 'termId', 'relevance']]);

        $this->assertNotSame([], $columns);
        $this->assertSame(['pid', 'termId', 'relevance'], $columns);
    }

    public function testAKnownTableIsUnaffectedByThatFallback(): void
    {
        $columns = $this->call('defaultColumns', ['tl_page', ['id', 'pid', 'title', 'alias', 'type', 'published', 'tstamp']]);

        $this->assertSame(['id', 'pid', 'title', 'alias', 'type', 'published', 'tstamp'], $columns);
    }

    // --- the allow-list against the real schema ---

    public function testCamelCaseColumnsSurviveTheSchemaComparison(): void
    {
        /**
         * The trap this guards: Doctrine's listTableColumns() lowercases its
         * array keys, so `singleSRC` comes back as `singlesrc`. Comparing with
         * array_intersect would drop every camelCase column Contao has while
         * looking like it worked, because most core columns are lowercase.
         */
        $connection = $this->createMock(Connection::class);
        $schemaManager = $this->createMock(\Doctrine\DBAL\Schema\AbstractSchemaManager::class);
        $schemaManager->method('listTableColumns')->willReturn([
            'id' => new \Doctrine\DBAL\Schema\Column('id', new \Doctrine\DBAL\Types\IntegerType()),
            'singlesrc' => new \Doctrine\DBAL\Schema\Column('singlesrc', new \Doctrine\DBAL\Types\BlobType()),
        ]);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $command = new RecordListCommand($this->createMock(ContaoFramework::class), $connection);
        $columns = (new \ReflectionMethod(RecordListCommand::class, 'existingColumns'))
            ->invokeArgs($command, ['tl_content', ['id', 'tstamp', 'singleSRC']]);

        $this->assertContains('singleSRC', $columns, 'The DCA spelling has to survive, not the lowercased one.');
        $this->assertNotContains('tstamp', $columns, 'A column the table does not have must be dropped.');
    }
}
