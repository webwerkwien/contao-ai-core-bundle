<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Writer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
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
    private function writer(array $collected, array $rows, ?Connection $connection = null): ModelWriter
    {
        $connection ??= $this->connection($rows);

        $collector = $this->createMock(RecordCascadeCollector::class);
        $collector->method('collect')->willReturn($collected);

        return new ModelWriter($connection, $this->createMock(VersionManager::class), $collector);
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
