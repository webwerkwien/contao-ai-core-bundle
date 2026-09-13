<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cache;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;

/**
 * What the back end does after every save, delete and restore, and what this
 * bundle did nowhere until v0.10.0.
 *
 * `DataContainer::invalidateCacheTags()` is identical in Contao 5.3, 5.7 and
 * 6.0: the record's tag, its parent's tag (or the table tag), then every
 * `oninvalidate_cache_tags_callback`. The callbacks are where
 * `contao.sitemap.<root>` comes from — tl_page, tl_news, tl_calendar and every
 * extension that puts its own addresses into the sitemap. Leaving them out
 * would refresh the pages and keep the sitemap a month out of date.
 */
class CacheTagInvalidatorTest extends TestCase
{
    /** @var list<list<string>> */
    private array $invalidated = [];

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA'] = [
            'tl_theme'   => ['config' => [], 'list' => ['sorting' => ['mode' => 1]]],
            'tl_article' => ['config' => ['ptable' => 'tl_page'], 'list' => ['sorting' => ['mode' => 4]]],
            'tl_content' => ['config' => ['dynamicPtable' => true, 'ctable' => ['tl_content']], 'list' => ['sorting' => ['mode' => 4]]],
            'tl_page'    => ['config' => [], 'list' => ['sorting' => ['mode' => 5]]],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    /**
     * @param array<string, array<string, mixed>> $rows keyed "<table>:<id>"
     */
    private function invalidator(array $rows, ?object $tagManager = null, ?object $cacheManager = null): CacheTagInvalidator
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($rows) {
                preg_match('/FROM `([^`]+)`/', $sql, $m);

                return $rows[$m[1] . ':' . $params[0]] ?? false;
            }
        );

        return new CacheTagInvalidator($connection, $tagManager ?? $this->recorder(), $cacheManager);
    }

    private function recorder(): object
    {
        $record = function (array $tags): void {
            $this->invalidated[] = $tags;
        };

        return new class ($record) {
            public function __construct(private readonly \Closure $record)
            {
            }

            /** @param list<string> $tags */
            public function invalidateTags(array $tags): void
            {
                ($this->record)($tags);
            }
        };
    }

    public function testATableWithoutParentGetsTheRecordAndTheTableTag(): void
    {
        $tags = $this->invalidator(['tl_theme:3' => ['id' => 3]])->collect('tl_theme', 3);

        $this->assertSame(['contao.db.tl_theme.3', 'contao.db.tl_theme'], $tags);
    }

    public function testAChildTableGetsItsParentRecordTag(): void
    {
        $tags = $this->invalidator(['tl_article:9' => ['id' => 9, 'pid' => 2]])->collect('tl_article', 9);

        $this->assertSame(['contao.db.tl_article.9', 'contao.db.tl_page.2'], $tags);
    }

    /**
     * 🔴 Found in the live test on c5, 2026-09-13: a content element created over
     * the CLI came back with `contao.db.tl_content` instead of
     * `contao.db.tl_article.1`. tl_content declares no ptable — `dynamicPtable`,
     * the parent is in each row. The back end does not know it from the DCA
     * either: `DC_Table::findPtable()` reads the record's `ptable` column, and the
     * constructor writes it into the DCA before `addPtableTags()` runs (5.3, 5.7,
     * 6.0). Without it, the page showing a new element is never refreshed.
     */
    public function testADynamicParentIsReadFromTheRecord(): void
    {
        $tags = $this->invalidator(['tl_content:647' => ['id' => 647, 'pid' => 1, 'ptable' => 'tl_article']])
            ->collect('tl_content', 647);

        $this->assertSame(['contao.db.tl_content.647', 'contao.db.tl_article.1'], $tags);
    }

    public function testANestedElementHasItsOwnTableAsParent(): void
    {
        $tags = $this->invalidator(['tl_content:650' => ['id' => 650, 'pid' => 646, 'ptable' => 'tl_content']])
            ->collect('tl_content', 650);

        $this->assertSame(['contao.db.tl_content.650', 'contao.db.tl_content.646'], $tags);
    }

    /**
     * In the back end, DynamicPtableListener sets config.ptable from `?do=` on
     * loadDataContainer — in a long-running process that value outlives the
     * request that set it. findPtable() lets the record win; so does this.
     */
    public function testTheRecordsParentWinsOverAPtableLeftInTheDca(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['config']['ptable'] = 'tl_news';

        $tags = $this->invalidator(['tl_content:647' => ['id' => 647, 'pid' => 1, 'ptable' => 'tl_article']])
            ->collect('tl_content', 647);

        $this->assertSame(['contao.db.tl_content.647', 'contao.db.tl_article.1'], $tags);
    }

    public function testADynamicTableWithoutAParentFallsBackToTheTableTag(): void
    {
        $tags = $this->invalidator(['tl_content:5' => ['id' => 5, 'pid' => 3, 'ptable' => '']])
            ->collect('tl_content', 5);

        $this->assertSame(['contao.db.tl_content.5', 'contao.db.tl_content'], $tags);
    }

    /**
     * Mode 5 is a tree: the parent of a page is a page. Contao's own words in
     * addPtableTags(): `mode == 5 ? $strTable : ptable`.
     */
    public function testATreeTableIsItsOwnParent(): void
    {
        $tags = $this->invalidator(['tl_page:2' => ['id' => 2, 'pid' => 1]])->collect('tl_page', 2);

        $this->assertSame(['contao.db.tl_page.2', 'contao.db.tl_page.1'], $tags);
    }

    public function testATopLevelRecordFallsBackToTheTableTag(): void
    {
        $tags = $this->invalidator(['tl_page:1' => ['id' => 1, 'pid' => 0]])->collect('tl_page', 1);

        $this->assertSame(['contao.db.tl_page.1', 'contao.db.tl_page'], $tags);
    }

    /**
     * tl_page reads `$dc->id`; tl_news, tl_calendar and tl_calendar_events read
     * `$dc->activeRecord->pid` / `->jumpTo`. Both have to be there, or three of
     * Contao's six sitemap callbacks read a property of null.
     *
     * `$dc` untyped, exactly as in Contao's own `addSitemapCacheInvalidationTag($dc, array $tags)`.
     */
    public function testTheCallbacksSeeTheRecordTheWayTheBackEndHandsItOver(): void
    {
        $seen = null;
        $GLOBALS['TL_DCA']['tl_page']['config']['oninvalidate_cache_tags_callback'] = [
            static function ($dc, array $tags) use (&$seen): array {
                $seen = [$dc->id, $dc->table, $dc->activeRecord->pid];

                return [...$tags, 'contao.sitemap.1'];
            },
        ];

        $tags = @$this->invalidator(['tl_page:2' => ['id' => 2, 'pid' => 1]])->collect('tl_page', 2);

        $this->assertSame([2, 'tl_page', 1], $seen);
        $this->assertSame(['contao.db.tl_page.2', 'contao.db.tl_page.1', 'contao.sitemap.1'], $tags);
    }

    public function testAFailingCallbackIsReportedAndTheOthersStillRun(): void
    {
        $GLOBALS['TL_DCA']['tl_theme']['config']['oninvalidate_cache_tags_callback'] = [
            static fn (): array => throw new \RuntimeException('no request on the console'),
            static fn (DataContainer $dc, array $tags): array => [...$tags, 'contao.sitemap.4'],
        ];

        $invalidator = $this->invalidator(['tl_theme:3' => ['id' => 3]]);
        $tags        = $invalidator->collect('tl_theme', 3);

        $warnings = $invalidator->drainReport()['warnings'];

        $this->assertContains('contao.sitemap.4', $tags);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('tl_theme', $warnings[0]);
        $this->assertStringContainsString('no request on the console', $warnings[0]);
    }

    public function testDuplicatesAndEmptyTagsAreDroppedBeforeInvalidating(): void
    {
        $this->invalidator([])->invalidate(['contao.db.tl_page.2', '', 'contao.db.tl_page.2', 'contao.sitemap.1']);

        $this->assertSame([['contao.db.tl_page.2', 'contao.sitemap.1']], $this->invalidated);
    }

    /**
     * Contao 5.3 has no contao.cache.tag_manager; DataContainer calls
     * fos_http_cache.cache_manager directly there.
     */
    public function testWithoutTheTagManagerTheFosCacheManagerIsUsed(): void
    {
        $connection = $this->createMock(Connection::class);
        (new CacheTagInvalidator($connection, null, $this->recorder()))->invalidate(['contao.sitemap.1']);

        $this->assertSame([['contao.sitemap.1']], $this->invalidated);
    }

    public function testWithNeitherServiceItSaysSoInsteadOfPretending(): void
    {
        $invalidator = new CacheTagInvalidator($this->createMock(Connection::class));
        $invalidator->invalidate(['contao.sitemap.1']);

        $report = $invalidator->drainReport();
        $this->assertSame([], $report['tags']);
        $this->assertCount(1, $report['warnings']);
    }

    public function testTheReportNamesWhatWasInvalidatedAndIsEmptiedByReading(): void
    {
        $invalidator = $this->invalidator([]);
        $invalidator->invalidate(['contao.db.tl_page.2']);

        $this->assertSame(['tags' => ['contao.db.tl_page.2'], 'warnings' => []], $invalidator->drainReport());
        $this->assertSame(['tags' => [], 'warnings' => []], $invalidator->drainReport());
    }
}
