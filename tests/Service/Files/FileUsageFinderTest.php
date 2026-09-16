<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Files;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Files\FileUsageFinder;

/**
 * Where a file is still used, before it is deleted.
 *
 * Contao checks nothing when a file is deleted (`DC_Folder::delete()`, 5.7.13 and 6.0.0):
 * an image element pointing at it by UUID keeps the UUID and renders nothing. There is
 * no `tl_undo` for files either. So `contao:file:delete` refuses a file in use unless
 * `--force` is given (Nr. 46 of the ConpAI 1.0 acceptance test, 2026-09-16).
 *
 * Which columns are searched is decided from the DCA, pinned here; the queries themselves
 * are verified live.
 */
class FileUsageFinderTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $fields
     * @param list<string>                         $columns
     *
     * @return array{uuid: list<string>, text: list<string>}
     */
    private function columns(array $fields, array $columns): array
    {
        return FileUsageFinder::searchableColumns($fields, $columns);
    }

    public function testAFileTreeFieldIsSearchedForTheBinaryUuid(): void
    {
        $result = $this->columns(['singleSRC' => ['inputType' => 'fileTree']], ['id', 'singleSRC']);

        $this->assertSame(['singleSRC'], $result['uuid']);
    }

    public function testTextFieldsAreSearchedForInsertTagsAndPaths(): void
    {
        $result = $this->columns([
            'text'     => ['inputType' => 'textarea'],
            'html'     => ['inputType' => 'textarea'],
            'headline' => ['inputType' => 'inputUnit'],
            'url'      => ['inputType' => 'text'],
        ], ['id', 'text', 'html', 'headline', 'url']);

        $this->assertSame(['text', 'html', 'headline', 'url'], $result['text']);
    }

    public function testAFieldWithoutAColumnIsSkipped(): void
    {
        $result = $this->columns(['singleSRC' => ['inputType' => 'fileTree'], 'text' => ['inputType' => 'textarea']], ['id']);

        $this->assertSame(['uuid' => [], 'text' => []], $result);
    }

    public function testOtherInputTypesAreNotSearched(): void
    {
        $result = $this->columns(['published' => ['inputType' => 'checkbox'], 'type' => ['inputType' => 'select']], ['published', 'type']);

        $this->assertSame(['uuid' => [], 'text' => []], $result);
    }

    public function testTheHistoryTablesAreNotSearched(): void
    {
        // A version or undo entry still holding the UUID is history, not a use.
        foreach (['tl_files', 'tl_version', 'tl_undo', 'tl_log'] as $table) {
            $this->assertFalse(FileUsageFinder::isSearchedTable($table), $table);
        }

        $this->assertTrue(FileUsageFinder::isSearchedTable('tl_content'));
        $this->assertTrue(FileUsageFinder::isSearchedTable('tl_consho_product'));
        $this->assertFalse(FileUsageFinder::isSearchedTable('doctrine_migration_versions'));
    }
}
