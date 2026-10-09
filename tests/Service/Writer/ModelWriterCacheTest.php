<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Writer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
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

    private function writer(CacheTagInvalidator $cacheTags): ModelWriter
    {
        return new ModelWriter(
            $this->createMock(Connection::class),
            $this->createMock(VersionManager::class),
            $this->createMock(RecordCascadeCollector::class),
            $cacheTags,
        );
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

    public int $id = 0;

    public static function findById(int $id): ?self
    {
        if (2 !== $id) {
            return null;
        }

        $record     = new self();
        $record->id = $id;

        return $record;
    }

    public function save(): void
    {
        self::$log[] = 'save tl_page:' . $this->id . ' title=' . ($this->title ?? '');
    }
}
