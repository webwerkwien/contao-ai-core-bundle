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
 * The update path — what every `--set`, `publish` and `repair` goes through.
 *
 * The back end invalidates in `DC_Table::submit()` after the record is written.
 * Invalidating before would let a request in between cache the old state again.
 */
class ModelWriterCacheTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_MODELS']['tl_page'] = CacheTestRecord::class;
        CacheTestRecord::$log = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_MODELS']);
    }

    private function writer(CacheTagInvalidator $cacheTags, ?DcaCallbacks $callbacks = null, ?VersionManager $versions = null, ?Connection $connection = null): ModelWriter
    {
        // The writer runs an update in one transaction (v1.4.0).
        if (null === $connection) {
            $connection = $this->columns($this->createMock(Connection::class));
            $connection->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());
        }

        return new ModelWriter(
            $connection,
            $versions ?? $this->createMock(VersionManager::class),
            $this->createMock(RecordCascadeCollector::class),
            $cacheTags,
            $callbacks,
        );
    }

    /**
     * The columns of tl_page these tests write; the writer checks keys against the
     * table on both paths (review M4).
     *
     * @param Connection&\PHPUnit\Framework\MockObject\MockObject $connection
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function columns(Connection $connection): Connection
    {
        $schema = $this->createMock(\Doctrine\DBAL\Schema\AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(array_map(
            static fn (string $c): \Doctrine\DBAL\Schema\Column => new \Doctrine\DBAL\Schema\Column($c, new \Doctrine\DBAL\Types\StringType()),
            ['id', 'tstamp', 'title', 'pid', 'ptable', 'sorting'],
        ));
        $connection->method('createSchemaManager')->willReturn($schema);

        return $connection;
    }

    /**
     * A transaction that records what happens inside it and rolls back on an
     * exception — what DBAL's transactional() does.
     */
    private function recordingTransaction(): Connection
    {
        $connection = $this->columns($this->createMock(Connection::class));
        $connection->expects($this->once())->method('transactional')->willReturnCallback(static function (callable $fn) {
            CacheTestRecord::$log[] = 'begin';

            try {
                $result = $fn();
            } catch (\Throwable $e) {
                CacheTestRecord::$log[] = 'rollback';

                throw $e;
            }

            CacheTestRecord::$log[] = 'commit';

            return $result;
        });

        return $connection;
    }

    /**
     * Review M1: removing transactional() around the update left every writer test
     * green. Save, callbacks, version and invalidation happen inside it.
     */
    public function testTheWholeUpdateRunsInsideOneTransaction(): void
    {
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('recordChanged')->willReturnCallback(static function (string $table, int $id): void {
            CacheTestRecord::$log[] = 'invalidate ' . $table . ':' . $id;
        });

        $this->writer($cacheTags, $this->loggingCallbacks(), $this->loggingVersions(), $this->recordingTransaction())
            ->update('tl_page', 2, ['title' => 'Neu'], 'claude');

        $this->assertSame('begin', CacheTestRecord::$log[0]);
        $this->assertSame('commit', end(CacheTestRecord::$log));
        $this->assertContains('save tl_page:2 title=Neu', CacheTestRecord::$log);
        $this->assertContains('invalidate tl_page:2', CacheTestRecord::$log);
    }

    /**
     * A callback that refuses: the exception leaves the transaction, which rolls the
     * save back, and nothing after it runs — no version, no invalidation.
     */
    public function testARefusingCallbackRollsTheSaveBack(): void
    {
        $callbacks = $this->createMock(DcaCallbacks::class);
        // onbeforesubmit (v1.5.0) hands the values back unchanged unless a test says otherwise.
        $callbacks->method('beforeSubmit')->willReturnArgument(2);
        $callbacks->method('submit')->willThrowException(new \InvalidArgumentException('tl_page.onsubmit refused by Probe::check. Nothing was written.'));

        $versions = $this->createMock(VersionManager::class);
        $versions->expects($this->never())->method('createVersion');

        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->expects($this->never())->method('recordChanged');

        try {
            $this->writer($cacheTags, $callbacks, $versions, $this->recordingTransaction())->update('tl_page', 2, ['title' => 'Neu'], 'claude');
            $this->fail('expected the refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Nothing was written', $e->getMessage());
        }

        $this->assertSame(['begin', 'save tl_page:2 title=Neu', 'rollback'], CacheTestRecord::$log);
    }

    /**
     * The model path is the one every command of this bundle takes (tl_page,
     * tl_content, tl_member …); BeforeSubmitTest covers the row-by-row path. Fable
     * review before v1.5.0: dropping the call here left the whole suite green.
     */
    public function testOnTheModelPathOnbeforesubmitRunsBeforeTheSaveAndItsResultIsSaved(): void
    {
        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->expects($this->once())->method('beforeSubmit')
            ->with('tl_page', 2, ['title' => 'Neu'])
            ->willReturnCallback(static function (string $t, int $id, array $values): array {
                CacheTestRecord::$log[] = 'beforesubmit';

                return ['title' => 'Neu (geprüft)', 'tstamp' => 1];
            });

        $this->writer($this->createMock(CacheTagInvalidator::class), $callbacks)->update('tl_page', 2, ['title' => 'Neu'], 'claude');

        $this->assertSame(['beforesubmit', 'save tl_page:2 title=Neu (geprüft)'], CacheTestRecord::$log);
    }

    public function testAKeyThatIsNoColumnIsRefusedOnTheModelPathToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a column of tl_page: titel. Nothing was written.');

        try {
            $this->writer($this->createMock(CacheTagInvalidator::class))->update('tl_page', 2, ['titel' => 'Neu'], 'claude');
        } finally {
            $this->assertSame([], CacheTestRecord::$log, 'nothing saved');
        }
    }

    public function testAnUpdateInvalidatesAfterTheRecordIsSaved(): void
    {
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('recordChanged')->willReturnCallback(
            static function (string $table, int $id): void {
                CacheTestRecord::$log[] = 'invalidate ' . $table . ':' . $id;
            }
        );

        $this->writer($cacheTags)->update('tl_page', 2, ['title' => 'Neu'], 'claude');

        $this->assertSame(['save tl_page:2 title=Neu', 'invalidate tl_page:2'], CacheTestRecord::$log);
    }

    /**
     * A move leaves the old parent's cached pages behind: it listed the record too
     * (a navigation, an article list). Its tags are read before the write, while the
     * record still names it, and invalidated after (v1.2.0).
     */
    public function testAMoveAlsoInvalidatesTheOldParent(): void
    {
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('collect')->willReturnCallback(
            static function (string $table, int $id): array {
                CacheTestRecord::$log[] = 'collect ' . $table . ':' . $id;

                return ['contao.db.tl_page.1'];
            }
        );
        $cacheTags->method('recordChanged')->willReturnCallback(
            static function (string $table, int $id): void {
                CacheTestRecord::$log[] = 'invalidate ' . $table . ':' . $id;
            }
        );
        $cacheTags->method('invalidate')->willReturnCallback(
            static function (array $tags): void {
                CacheTestRecord::$log[] = 'invalidate ' . implode(',', $tags);
            }
        );

        $this->writer($cacheTags)->update('tl_page', 2, ['pid' => 5], 'claude');

        $this->assertSame(
            ['collect tl_page:2', 'save tl_page:2 title=', 'invalidate tl_page:2', 'invalidate contao.db.tl_page.1'],
            CacheTestRecord::$log,
        );
    }

    /**
     * 🔴 On c5 (Contao 5.7, 2026-10-09) every page move answered "The model instance
     * has been detached and cannot be saved": tl_page's sitemap callback calls
     * `PageModel::findWithDetails()`, and `loadDetails()` takes the instance out of
     * the registry — the one findById() had just returned. Collecting before the
     * lookup lets findById() load a fresh, attached instance.
     */
    public function testTheOldParentIsCollectedBeforeTheRecordIsLoaded(): void
    {
        CacheTestRecord::$logFind = true;

        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('collect')->willReturnCallback(
            static function (string $table, int $id): array {
                CacheTestRecord::$log[] = 'collect ' . $table . ':' . $id;

                return [];
            }
        );

        try {
            $this->writer($cacheTags)->update('tl_page', 2, ['pid' => 5], 'claude');
        } finally {
            CacheTestRecord::$logFind = false;
        }

        $this->assertSame(['collect tl_page:2', 'find tl_page:2'], \array_slice(CacheTestRecord::$log, 0, 2));
    }

    public function testAnUpdateWithoutAMoveCollectsNothingBefore(): void
    {
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->expects($this->never())->method('collect');
        $cacheTags->expects($this->never())->method('invalidate');

        $this->writer($cacheTags)->update('tl_page', 2, ['title' => 'Neu'], 'claude');
    }

    public function testARecordThatDoesNotExistInvalidatesNothing(): void
    {
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->expects($this->never())->method('recordChanged');

        $this->assertNull($this->writer($cacheTags)->update('tl_page', 404, ['title' => 'Neu'], 'claude'));
    }

    private function loggingCallbacks(): DcaCallbacks
    {
        $callbacks = $this->createMock(DcaCallbacks::class);
        // onbeforesubmit (v1.5.0) hands the values back unchanged unless a test says otherwise.
        $callbacks->method('beforeSubmit')->willReturnArgument(2);
        $callbacks->method('submit')->willReturnCallback(static function (string $table, int $id): void {
            CacheTestRecord::$log[] = 'onsubmit ' . $table . ':' . $id;
        });
        $callbacks->method('cut')->willReturnCallback(static function (string $table, int $id): void {
            CacheTestRecord::$log[] = 'oncut ' . $table . ':' . $id;
        });

        return $callbacks;
    }

    private function loggingVersions(): VersionManager
    {
        $versions = $this->createMock(VersionManager::class);
        $versions->method('initialize')->willReturnCallback(static function (): void {
            CacheTestRecord::$log[] = 'initialize';
        });
        $versions->method('createVersion')->willReturnCallback(static function (): void {
            CacheTestRecord::$log[] = 'version';
        });

        return $versions;
    }

    /**
     * As DC_Table::edit(): the state being replaced is versioned if it has no version,
     * the record is saved, onsubmit runs, and only then the new version (v1.4.0).
     */
    public function testASaveRunsOnsubmitBeforeTheNewVersion(): void
    {
        $this->writer($this->createMock(CacheTagInvalidator::class), $this->loggingCallbacks(), $this->loggingVersions())
            ->update('tl_page', 2, ['title' => 'Neu'], 'claude');

        $this->assertSame(['initialize', 'save tl_page:2 title=Neu', 'onsubmit tl_page:2', 'version'], CacheTestRecord::$log);
    }

    /**
     * A new parent is a cut and paste in the back end: oncut, not onsubmit — the mask
     * was never saved (v1.4.0).
     */
    public function testAMoveRunsOncutAndNotOnsubmit(): void
    {
        $this->writer($this->createMock(CacheTagInvalidator::class), $this->loggingCallbacks(), $this->loggingVersions())
            ->update('tl_page', 2, ['pid' => 5, 'sorting' => 128], 'claude');

        $this->assertSame(['initialize', 'save tl_page:2 title=', 'oncut tl_page:2', 'version'], CacheTestRecord::$log);
    }

    public function testAMoveTogetherWithAFieldRunsBoth(): void
    {
        $this->writer($this->createMock(CacheTagInvalidator::class), $this->loggingCallbacks())
            ->update('tl_page', 2, ['ptable' => 'tl_news', 'title' => 'Neu'], 'claude');

        $this->assertSame(['save tl_page:2 title=Neu', 'oncut tl_page:2', 'onsubmit tl_page:2'], CacheTestRecord::$log);
    }

    public function testTheSameParentAgainIsNoMove(): void
    {
        // CacheTestRecord::row() has pid 1.
        $this->writer($this->createMock(CacheTagInvalidator::class), $this->loggingCallbacks())
            ->update('tl_page', 2, ['pid' => '1', 'title' => 'Neu'], 'claude');

        $this->assertSame(['save tl_page:2 title=Neu', 'onsubmit tl_page:2'], CacheTestRecord::$log);
    }
}

/**
 * Stands in for a Contao model: ModelWriter only calls findById(), sets
 * properties and calls save().
 */
#[\AllowDynamicProperties]
final class CacheTestRecord
{
    /** @var list<string> */
    public static array $log = [];

    public static bool $logFind = false;

    public int $id = 0;

    public static function findById(int $id): ?self
    {
        if (self::$logFind) {
            self::$log[] = 'find tl_page:' . $id;
        }

        if (2 !== $id) {
            return null;
        }

        $record     = new self();
        $record->id = $id;

        return $record;
    }

    /**
     * The stored row; the writer compares pid/ptable against it to tell a move (v1.4.0).
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return ['id' => $this->id, 'pid' => 1, 'ptable' => ''];
    }

    public function save(): void
    {
        self::$log[] = 'save tl_page:' . $this->id . ' title=' . ($this->title ?? '');
    }
}
