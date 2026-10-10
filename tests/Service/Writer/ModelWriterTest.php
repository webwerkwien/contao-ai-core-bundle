<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Writer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\RecordCascadeCollector;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\ModelWriter;

/**
 * The delete path, which is where the audit machinery is densest.
 *
 * Everything here used to live inside AbstractModelDeleteCommand as private
 * methods, reachable only through a CommandTester with a booted Contao
 * container — which is why the cascade fixes of v0.2.8/v0.2.9 and the
 * tl_undo.pid correction shipped without a single test covering them.
 *
 * Behind the writer interface the same logic is plain collaborators: a
 * Connection and a cascade collector. No container, no console.
 */
class ModelWriterTest extends TestCase
{
    /**
     * @param array<string, list<int>>              $collected
     * @param array<string, array<string, mixed>>   $rows       keyed "<table>:<id>"
     */
    private function writer(array $collected, array $rows, ?Connection $connection = null, ?CacheTagInvalidator $cacheTags = null, ?DcaCallbacks $callbacks = null): ModelWriter
    {
        $connection ??= $this->connection($rows);

        $collector = $this->createMock(RecordCascadeCollector::class);
        $collector->method('collect')->willReturn($collected);

        return new ModelWriter($connection, $this->createMock(VersionManager::class), $collector, $cacheTags, $callbacks);
    }

    /**
     * @param list<string> $columns
     */
    private function connectionWithColumns(array $columns): Connection
    {
        $schema = $this->createMock(\Doctrine\DBAL\Schema\AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(array_map(
            static fn (string $c): \Doctrine\DBAL\Schema\Column => new \Doctrine\DBAL\Schema\Column($c, new \Doctrine\DBAL\Types\StringType()),
            $columns,
        ));

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $connection->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());

        return $connection;
    }

    /**
     * The public API's create (v1.4.0): insert, oncreate, onsubmit, then the first
     * version — the order of the bundle's own creates.
     */
    public function testCreateInsertsRunsTheCreateCallbacksThenTheFirstVersion(): void
    {
        $order      = [];
        $connection = $this->connectionWithColumns(['id', 'tstamp', 'title']);
        $connection->method('insert')->willReturnCallback(static function (string $table, array $row) use (&$order): int {
            $order[] = 'insert ' . $table . ' ' . implode(',', array_keys($row));

            return 1;
        });
        $connection->method('lastInsertId')->willReturn('42');

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('create')->willReturnCallback(static function (string $t, int $id) use (&$order): void { $order[] = 'oncreate ' . $id; });
        $callbacks->method('submit')->willReturnCallback(static function (string $t, int $id) use (&$order): void { $order[] = 'onsubmit ' . $id; });

        $versions = $this->createMock(VersionManager::class);
        $versions->method('createInitialVersion')->willReturnCallback(static function (string $t, int $id) use (&$order): int { $order[] = 'version ' . $id; return 0; });

        $writer = new ModelWriter($connection, $versions, $this->createMock(RecordCascadeCollector::class), null, $callbacks);

        $this->assertSame(42, $writer->create('tl_consho_shop', ['title' => 'Laden'], 'claude'));
        $this->assertSame(['insert tl_consho_shop `tstamp`,`title`', 'oncreate 42', 'onsubmit 42', 'version 42'], $order);
    }

    public function testCreateRefusesAKeyThatIsNoColumnBeforeWriting(): void
    {
        $connection = $this->connectionWithColumns(['id', 'tstamp', 'title']);
        $connection->expects($this->never())->method('insert');

        $writer = new ModelWriter($connection, $this->createMock(VersionManager::class), $this->createMock(RecordCascadeCollector::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a column of tl_consho_shop: titel. Nothing was written.');

        $writer->create('tl_consho_shop', ['titel' => 'Laden'], 'claude');
    }

    /**
     * An extension's table has no Contao model; it is written row by row (v1.4.0).
     */
    public function testATableWithoutAModelIsUpdatedRowByRow(): void
    {
        $order      = [];
        $connection = $this->connectionWithColumns(['id', 'tstamp', 'title']);
        $connection->method('fetchAssociative')->willReturn(['id' => 3, 'title' => 'Alt']);
        $connection->method('update')->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$order): int {
            $order[] = 'update ' . $table . ':' . $criteria['id'] . ' ' . implode(',', array_keys($data));

            return 1;
        });

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('submit')->willReturnCallback(static function (string $t, int $id) use (&$order): void { $order[] = 'onsubmit ' . $id; });

        $writer = new ModelWriter($connection, $this->createMock(VersionManager::class), $this->createMock(RecordCascadeCollector::class), null, $callbacks);

        $this->assertSame(['title'], $writer->update('tl_contao_ai_no_model', 3, ['title' => 'Neu'], 'claude'));
        $this->assertSame(['update tl_contao_ai_no_model:3 `tstamp`,`title`', 'onsubmit 3'], $order);
    }

    /**
     * DC_Table::delete(): ondelete for the record itself — not its children — after the
     * undo row, with that row's ID, and before anything is deleted (v1.4.0).
     */
    public function testOndeleteRunsForTheRecordBetweenTheUndoEntryAndTheDelete(): void
    {
        $order      = [];
        $connection = $this->connection(['tl_page:5' => ['id' => 5, 'title' => 'Start'], 'tl_article:9' => ['id' => 9, 'pid' => 5]]);
        $connection->method('insert')->willReturnCallback(static function (string $table) use (&$order): int {
            $order[] = 'insert ' . $table;

            return 1;
        });
        $connection->method('lastInsertId')->willReturn('41');
        $connection->method('delete')->willReturnCallback(static function (string $table, array $criteria) use (&$order): int {
            $order[] = 'delete ' . $table . ':' . $criteria['id'];

            return 1;
        });

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->expects($this->once())->method('delete')->willReturnCallback(
            static function (string $table, int $id, array $row, int $undoId) use (&$order): void {
                $order[] = \sprintf('ondelete %s:%d undo %d title %s', $table, $id, $undoId, $row['title']);
            }
        );

        $this->writer(['tl_page' => [5], 'tl_article' => [9]], [], $connection, null, $callbacks)->delete('tl_page', 5, 'claude', 0);

        $this->assertSame(['insert tl_undo', 'ondelete tl_page:5 undo 41 title Start', 'delete tl_article:9', 'delete tl_page:5'], $order);
    }

    /**
     * @param array<string, array<string, mixed>> $rows keyed "<table>:<id>"
     */
    private function connection(array $rows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $n): string => '`' . $n . '`'
        );
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($rows) {
                preg_match('/FROM `([^`]+)`/', $sql, $m);
                return $rows[$m[1] . ':' . $params[0]] ?? false;
            }
        );
        $connection->method('delete')->willReturn(1);
        // The writer runs a delete in one transaction (v1.4.0).
        $connection->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());

        return $connection;
    }

    public function testDeletesChildrenBeforeTheirParent(): void
    {
        $order = [];
        $connection = $this->connection([]);
        $connection->method('delete')->willReturnCallback(
            static function (string $table, array $criteria) use (&$order): int {
                $order[] = $table . ':' . $criteria['id'];
                return 1;
            }
        );

        $this->writer(
            ['tl_page' => [5], 'tl_article' => [9], 'tl_content' => [12, 13]],
            [],
            $connection,
        )->delete('tl_page', 5, 'claude', 0);

        $this->assertSame(
            ['tl_content:12', 'tl_content:13', 'tl_article:9', 'tl_page:5'],
            $order,
            'A parent removed first leaves rows pointing at something that is already gone.',
        );
    }

    public function testWritesOneUndoEntryForTheWholeCascade(): void
    {
        $inserts = [];
        $connection = $this->connection([
            'tl_page:5'    => ['id' => 5, 'title' => 'Start'],
            'tl_article:9' => ['id' => 9, 'pid' => 5],
        ]);
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$inserts): int {
                $inserts[] = [$table, $data];
                return 1;
            }
        );

        $this->writer(['tl_page' => [5], 'tl_article' => [9]], [
            'tl_page:5'    => ['id' => 5, 'title' => 'Start'],
            'tl_article:9' => ['id' => 9, 'pid' => 5],
        ], $connection)->delete('tl_page', 5, 'claude', 7);

        $this->assertCount(1, $inserts, 'Restoring has to bring the children back with the parent.');
        [$table, $data] = $inserts[0];

        $this->assertSame('tl_undo', $table);
        $this->assertSame('tl_page', $data['fromTable']);
        $this->assertSame(2, $data['affectedRows']);
        $this->assertSame(['tl_page', 'tl_article'], array_keys(unserialize($data['data'])));
    }

    /**
     * tl_undo.pid is the back end user who deleted the record — the undo module
     * filters non-admins on it. Using the record's author put the entry in the
     * wrong person's undo list; a plain CLI deletion has no back end user at all.
     */
    public function testTheUndoEntryIsFiledUnderTheDeletingUser(): void
    {
        $inserts = [];
        $connection = $this->connection(['tl_page:5' => ['id' => 5]]);
        $connection->method('insert')->willReturnCallback(
            static function (string $t, array $d) use (&$inserts): int {
                $inserts[] = $d;
                return 1;
            }
        );

        $this->writer(['tl_page' => [5]], ['tl_page:5' => ['id' => 5]], $connection)
            ->delete('tl_page', 5, 'webwerkwien', 7);

        $this->assertSame(7, $inserts[0]['pid']);
    }

    public function testNothingIsFiledForUndoWhenNoRowsCouldBeRead(): void
    {
        $inserts = 0;
        $connection = $this->connection([]);
        $connection->method('insert')->willReturnCallback(
            static function () use (&$inserts): int {
                ++$inserts;
                return 1;
            }
        );

        $this->writer(['tl_page' => [5]], [], $connection)->delete('tl_page', 5, 'claude', 0);

        $this->assertSame(0, $inserts, 'An empty snapshot restores nothing and only clutters the undo list.');
    }

    /**
     * `DC_Table::delete()` invalidates BEFORE its DELETE loop — in 5.3, 5.7 and
     * 6.0 alike. The sitemap callbacks look the record up: a page that is
     * already gone has no root, and its sitemap would never be invalidated.
     *
     * Only the root record, as in Contao: the children's pages are covered by
     * the parent's tag.
     */
    public function testCacheTagsAreCollectedWhileTheRecordStillExistsAndInvalidatedAfterwards(): void
    {
        $order      = [];
        $connection = $this->connection([]);
        $connection->method('delete')->willReturnCallback(
            static function (string $table, array $criteria) use (&$order): int {
                $order[] = 'delete ' . $table . ':' . $criteria['id'];
                return 1;
            }
        );

        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('collect')->willReturnCallback(
            static function (string $table, int $id) use (&$order): array {
                $order[] = 'collect ' . $table . ':' . $id;
                return ['contao.db.tl_page.5', 'contao.sitemap.1'];
            }
        );
        $cacheTags->method('invalidate')->willReturnCallback(
            static function (array $tags) use (&$order): void {
                $order[] = 'invalidate ' . implode(',', $tags);
            }
        );

        $this->writer(['tl_page' => [5], 'tl_article' => [9]], [], $connection, $cacheTags)
            ->delete('tl_page', 5, 'claude', 0);

        $this->assertSame([
            'collect tl_page:5',
            'delete tl_article:9',
            'delete tl_page:5',
            'invalidate contao.db.tl_page.5,contao.sitemap.1',
        ], $order);
    }

    public function testTheResultCountsWhatWasRemoved(): void
    {
        $result = $this->writer(
            ['tl_page' => [5], 'tl_article' => [9], 'tl_content' => [12, 13]],
            ['tl_page:5' => ['id' => 5]],
        )->delete('tl_page', 5, 'claude', 0);

        $this->assertSame(['tl_page' => 1, 'tl_article' => 1, 'tl_content' => 2], $result['cascade']);
        $this->assertSame(4, $result['rowsTotal']);
    }
}
