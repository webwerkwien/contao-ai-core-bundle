<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\RecordCascadeCollector;

/**
 * The traversal is tested against DCA and row fixtures rather than a live Contao
 * installation. What it has to reproduce is DC_Table::delete()'s collection step —
 * miss a branch here and the delete leaves orphans that nothing in Contao reclaims.
 */
class RecordCascadeCollectorTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>>            $dca
     * @param array<string, list<array<string, mixed>>>      $rows
     */
    private function collector(array $dca, array $rows = []): RecordCascadeCollector
    {
        return new class ($this->createMock(Connection::class), $dca, $rows) extends RecordCascadeCollector {
            /**
             * @param array<string, array<string, mixed>>       $dcaFixture
             * @param array<string, list<array<string, mixed>>> $rowFixture
             */
            public function __construct(
                Connection $connection,
                private readonly array $dcaFixture,
                private readonly array $rowFixture,
            ) {
                parent::__construct($connection);
            }

            protected function dca(string $table): array
            {
                return $this->dcaFixture[$table] ?? [];
            }

            protected function hasColumn(string $table, string $column): bool
            {
                return true;
            }

            protected function fetchIds(string $table, array $pids, ?string $ptable): array
            {
                $ids = [];

                foreach ($this->rowFixture[$table] ?? [] as $row) {
                    if (!\in_array((int) $row['pid'], array_map('\intval', $pids), true)) {
                        continue;
                    }

                    if (null !== $ptable && ($row['ptable'] ?? null) !== $ptable) {
                        continue;
                    }

                    $ids[] = (int) $row['id'];
                }

                return $ids;
            }
        };
    }

    private const PAGE_DCA = [
        'tl_page'    => ['config' => ['ctable' => ['tl_article']],
                         'list' => ['sorting' => ['mode' => DataContainer::MODE_TREE]]],
        'tl_article' => ['config' => ['ptable' => 'tl_page', 'ctable' => ['tl_content']]],
        'tl_content' => ['config' => ['ptable' => 'tl_article', 'ctable' => ['tl_content'],
                                      'dynamicPtable' => true]],
    ];

    public function testRecordWithoutChildTablesCollectsOnlyItself(): void
    {
        $collector = $this->collector(['tl_faq' => ['config' => ['ptable' => 'tl_faq_category']]]);

        $this->assertSame(['tl_faq' => [7]], $collector->collect('tl_faq', 7));
    }

    public function testTreeTableTakesTheWholeSubtree(): void
    {
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_page' => [
                ['id' => 2, 'pid' => 1],
                ['id' => 3, 'pid' => 2],   // Enkel
                ['id' => 4, 'pid' => 99],  // fremder Ast
            ],
        ]);

        $this->assertSame(['tl_page' => [1, 2, 3]], $collector->collect('tl_page', 1));
    }

    public function testCascadeFollowsCtableRecursively(): void
    {
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_article' => [['id' => 10, 'pid' => 1]],
            'tl_content' => [['id' => 100, 'pid' => 10, 'ptable' => 'tl_article']],
        ]);

        $this->assertSame(
            ['tl_page' => [1], 'tl_article' => [10], 'tl_content' => [100]],
            $collector->collect('tl_page', 1)
        );
    }

    public function testDynamicPtableChildrenAreMatchedOnPtableToo(): void
    {
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_article' => [['id' => 10, 'pid' => 1]],
            'tl_content' => [
                ['id' => 100, 'pid' => 10, 'ptable' => 'tl_article'],
                // Gleiche pid, anderes ptable — gehoert zu einer News, nicht hierher.
                ['id' => 200, 'pid' => 10, 'ptable' => 'tl_news'],
            ],
        ]);

        $result = $collector->collect('tl_page', 1);

        $this->assertSame([100], $result['tl_content']);
        $this->assertNotContains(200, $result['tl_content']);
    }

    public function testNestedContentElementsAreIncluded(): void
    {
        /** tl_content has ctable => ['tl_content'] — accordion and grid children. */
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_article' => [['id' => 10, 'pid' => 1]],
            'tl_content' => [
                ['id' => 100, 'pid' => 10,  'ptable' => 'tl_article'],
                ['id' => 101, 'pid' => 100, 'ptable' => 'tl_content'],
                ['id' => 102, 'pid' => 101, 'ptable' => 'tl_content'],
            ],
        ]);

        $this->assertSame([100, 101, 102], $collector->collect('tl_page', 1)['tl_content']);
    }

    public function testDoNotDeleteRecordsIsHonoured(): void
    {
        $dca = self::PAGE_DCA;
        $dca['tl_article']['config']['doNotDeleteRecords'] = true;

        $collector = $this->collector($dca, [
            'tl_article' => [['id' => 10, 'pid' => 1]],
            'tl_content' => [['id' => 100, 'pid' => 10, 'ptable' => 'tl_article']],
        ]);

        $this->assertSame(['tl_page' => [1]], $collector->collect('tl_page', 1));
    }

    public function testSelfReferencingRowDoesNotLoop(): void
    {
        /** Corrupt data: a content element that is its own parent. */
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_article' => [['id' => 10, 'pid' => 1]],
            'tl_content' => [
                ['id' => 100, 'pid' => 10,  'ptable' => 'tl_article'],
                ['id' => 100, 'pid' => 100, 'ptable' => 'tl_content'],
            ],
        ]);

        $this->assertSame([100], $collector->collect('tl_page', 1)['tl_content']);
    }

    public function testRootTableComesFirst(): void
    {
        /** The delete runs the result in reverse, so children must not precede the parent. */
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_article' => [['id' => 10, 'pid' => 1]],
            'tl_content' => [['id' => 100, 'pid' => 10, 'ptable' => 'tl_article']],
        ]);

        $this->assertSame(
            ['tl_page', 'tl_article', 'tl_content'],
            array_keys($collector->collect('tl_page', 1))
        );
    }

    public function testTreeSubtreeAndChildTablesCombine(): void
    {
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_page'    => [['id' => 2, 'pid' => 1]],
            'tl_article' => [['id' => 10, 'pid' => 1], ['id' => 11, 'pid' => 2]],
            'tl_content' => [['id' => 100, 'pid' => 11, 'ptable' => 'tl_article']],
        ]);

        $this->assertSame(
            ['tl_page' => [1, 2], 'tl_article' => [10, 11], 'tl_content' => [100]],
            $collector->collect('tl_page', 1)
        );
    }

    public function testNonTreeTableIgnoresSameTableChildren(): void
    {
        /** tl_article has a ptable, so a pid match inside tl_article is not a subtree. */
        $collector = $this->collector(self::PAGE_DCA, [
            'tl_article' => [['id' => 11, 'pid' => 10]],
        ]);

        $this->assertSame(['tl_article' => [10]], $collector->collect('tl_article', 10));
    }
}
