<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\ThemeCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * The theme clone (v1.9.0) against a small in-memory database: everything in the theme is
 * copied, and the references INTO the theme point at the copies — what a plain copy of the
 * rows would leave on the old theme (Contao has no theme copy: tl_theme is notCopyable).
 * References out of the theme stay.
 */
class ThemeClonerTest extends TestCase
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var list<string> */
    private array $log = [];

    private int $nextId = 100;

    protected function setUp(): void
    {
        $int     = 'int(10) unsigned NOT NULL default 0';
        $varchar = "varchar(255) NOT NULL default ''";
        $size    = ['inputType' => 'imageSize', 'sql' => $varchar];

        $GLOBALS['TL_DCA'] = [
            'tl_theme' => ['fields' => [
                'name'       => ['eval' => ['unique' => true], 'sql' => $varchar],
                'templates'  => ['inputType' => 'select', 'sql' => $varchar],
                'screenshot' => ['inputType' => 'fileTree', 'sql' => 'binary(16) NULL'],
            ]],
            'tl_image_size' => ['fields' => ['name' => ['sql' => $varchar]]],
            'tl_image_size_item' => ['fields' => ['media' => ['sql' => $varchar]]],
            'tl_content' => ['fields' => [
                'sorting' => ['sql' => $int],
                'ptable'  => ['sql' => $varchar],
                'module'  => ['foreignKey' => 'tl_module.name', 'sql' => $int],
                'cteAlias' => ['inputType' => 'picker', 'relation' => ['type' => 'hasOne', 'table' => 'tl_content'], 'sql' => $int],
                'size'    => $size,
            ]],
            'tl_module' => ['fields' => [
                'name'               => ['sql' => $varchar],
                'news_readerModule'  => ['inputType' => 'select', 'sql' => $int],
                'cal_readerModule'   => ['relation' => ['table' => 'tl_module', 'type' => 'hasOne'], 'sql' => $int],
                'jumpTo'             => ['inputType' => 'pageTree', 'foreignKey' => 'tl_page.title', 'sql' => $int],
                'imgSize'            => $size,
                'rootPageDependentModules' => ['inputType' => 'rootPageDependentSelect', 'sql' => 'blob NULL'],
            ]],
            'tl_layout' => ['fields' => [
                'name'         => ['sql' => $varchar],
                'modules'      => ['inputType' => 'moduleWizard', 'sql' => 'blob NULL'],
                'lightboxSize' => $size,
            ]],
        ];

        $this->tables = [
            'tl_theme' => [
                1 => ['id' => 1, 'tstamp' => 1, 'name' => 'Basis', 'templates' => 'templates/basis', 'screenshot' => null],
                2 => ['id' => 2, 'tstamp' => 1, 'name' => 'Anderes', 'templates' => '', 'screenshot' => null],
            ],
            'tl_image_size' => [50 => ['id' => 50, 'pid' => 1, 'tstamp' => 1, 'name' => 'Teaser']],
            'tl_image_size_item' => [60 => ['id' => 60, 'pid' => 50, 'tstamp' => 1, 'media' => '(max-width: 600px)']],
            'tl_content' => [
                70 => ['id' => 70, 'pid' => 1, 'ptable' => 'tl_theme', 'tstamp' => 1, 'sorting' => 1, 'module' => '10', 'size' => serialize(['', '', '50'])],
                71 => ['id' => 71, 'pid' => 70, 'ptable' => 'tl_content', 'tstamp' => 1, 'sorting' => 1, 'module' => '0', 'size' => ''],
                // Same pid, other parent: an article element, not part of the theme.
                72 => ['id' => 72, 'pid' => 1, 'ptable' => 'tl_article', 'tstamp' => 1, 'sorting' => 2, 'module' => '10', 'size' => ''],
                // A theme element aliasing another one, and one aliasing an article element.
                73 => ['id' => 73, 'pid' => 1, 'ptable' => 'tl_theme', 'tstamp' => 1, 'sorting' => 3, 'module' => '0', 'size' => '', 'cteAlias' => '70'],
                74 => ['id' => 74, 'pid' => 1, 'ptable' => 'tl_theme', 'tstamp' => 1, 'sorting' => 4, 'module' => '0', 'size' => '', 'cteAlias' => '72'],
            ],
            'tl_module' => [
                10 => ['id' => 10, 'pid' => 1, 'tstamp' => 1, 'name' => 'Newsliste', 'news_readerModule' => '11', 'cal_readerModule' => '0', 'jumpTo' => '5', 'imgSize' => serialize(['', '', '50'])],
                11 => ['id' => 11, 'pid' => 1, 'tstamp' => 1, 'name' => 'Newsleser', 'news_readerModule' => '0', 'cal_readerModule' => '0', 'jumpTo' => '0', 'imgSize' => serialize(['300', '200', 'crop'])],
                12 => ['id' => 12, 'pid' => 1, 'tstamp' => 1, 'name' => 'Eventliste', 'news_readerModule' => '0', 'cal_readerModule' => '99', 'jumpTo' => '0', 'imgSize' => ''],
                13 => ['id' => 13, 'pid' => 1, 'tstamp' => 1, 'name' => 'Je Root', 'news_readerModule' => '0', 'cal_readerModule' => '0', 'jumpTo' => '0', 'imgSize' => '', 'rootPageDependentModules' => serialize([1 => '11', 2 => 'content-70', 3 => '99', 4 => ''])],
                99 => ['id' => 99, 'pid' => 2, 'tstamp' => 1, 'name' => 'Fremder Leser', 'news_readerModule' => '0', 'cal_readerModule' => '0', 'jumpTo' => '0', 'imgSize' => ''],
            ],
            'tl_layout' => [20 => ['id' => 20, 'pid' => 1, 'tstamp' => 1, 'name' => 'Standard', 'lightboxSize' => serialize(['', '', '50']), 'modules' => serialize([
                ['mod' => '10', 'col' => 'main', 'enable' => '1'],
                ['mod' => 'content-70', 'col' => 'header', 'enable' => '1'],
                ['mod' => '99', 'col' => 'left', 'enable' => '1'],
                ['mod' => '0', 'col' => 'main', 'enable' => '1'],
            ])]],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    private function cloner(): ThemeCloner
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work());
        $connection->method('fetchAssociative')->willReturnCallback(
            fn (string $sql, array $params): array|false => $this->tables[self::tableOf($sql)][(int) $params[0]] ?? false,
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params): array {
            $rows = array_filter(
                $this->tables[self::tableOf($sql)] ?? [],
                static fn (array $row): bool => (int) $row['pid'] === (int) $params[0] && (!isset($params[1]) || $row['ptable'] === $params[1]),
            );

            return array_values($rows);
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params): mixed {
            foreach ($this->tables['tl_theme'] as $row) {
                if ($row['name'] === $params[0]) {
                    return 1;
                }
            }

            return false;
        });
        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $row = [];
            foreach ($data as $column => $value) {
                $row[trim($column, '`')] = $value;
            }
            $id = $this->nextId++;
            $this->tables[trim($table, '`')][$id] = ['id' => $id] + $row;
            $this->log[] = 'insert ' . trim($table, '`') . ":$id";

            return 1;
        });
        $connection->method('lastInsertId')->willReturnCallback(fn (): int => $this->nextId - 1);
        $connection->method('update')->willReturnCallback(function (string $table, array $data, array $criteria): int {
            foreach ($data as $column => $value) {
                $this->tables[trim($table, '`')][$criteria['id']][trim($column, '`')] = $value;
            }
            $this->log[] = 'update ' . trim($table, '`') . ':' . $criteria['id'];

            return 1;
        });

        $versions = $this->createMock(VersionManager::class);
        $versions->method('createInitialVersion')->willReturnCallback(function (string $table, int $id): int {
            $this->log[] = "version $table:$id";

            return 0;
        });
        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('copy')->willReturnCallback(function (string $table, int $newId, int $sourceId): void {
            $this->log[] = "oncopy $table:$newId<-$sourceId";
        });

        return new ThemeCloner($connection, $this->createMock(ContaoFramework::class), $versions, $callbacks);
    }

    private static function tableOf(string $sql): string
    {
        preg_match('/FROM `(tl_[a-z_]+)`/', $sql, $match);

        return $match[1] ?? '';
    }

    /**
     * The copy of $table row $sourceId, found by the id map the inserts imply.
     *
     * @return array<string, mixed>
     */
    private function copyOf(string $table, string $field, mixed $value): array
    {
        foreach ($this->tables[$table] as $id => $row) {
            if ($id >= 100 && ($row[$field] ?? null) === $value) {
                return $row;
            }
        }

        self::fail("no copy of $table with $field = " . var_export($value, true));
    }

    public function testEverythingInTheThemeIsCopiedAndNothingElse(): void
    {
        $result = $this->cloner()->clone(1, [], 'k.jones');

        self::assertSame('tl_theme', $result['table']);
        self::assertSame('Basis (Kopie)', $this->tables['tl_theme'][$result['id']]['name']);
        self::assertSame(
            ['tl_image_size' => 1, 'tl_content' => 4, 'tl_module' => 4, 'tl_layout' => 1, 'tl_image_size_item' => 1],
            $result['children'],
        );
        self::assertSame(4, $result['count']);
        self::assertStringContainsString('no theme copy', $result['note']);
        // The article element with the same pid stayed where it was.
        self::assertCount(1, array_filter($this->tables['tl_content'], static fn (array $r): bool => 'tl_article' === $r['ptable']));
    }

    public function testTheCopiesPointAtEachOther(): void
    {
        $theme = $this->cloner()->clone(1, [], 'k.jones')['id'];

        $size    = $this->copyOf('tl_image_size', 'pid', $theme);
        $list    = $this->copyOf('tl_module', 'name', 'Newsliste');
        $reader  = $this->copyOf('tl_module', 'name', 'Newsleser');
        $events  = $this->copyOf('tl_module', 'name', 'Eventliste');
        $element = $this->copyOf('tl_content', 'ptable', 'tl_theme');
        $nested  = $this->copyOf('tl_content', 'ptable', 'tl_content');
        $layout  = $this->copyOf('tl_layout', 'name', 'Standard');

        self::assertSame($size['id'], $this->copyOf('tl_image_size_item', 'media', '(max-width: 600px)')['pid']);
        self::assertSame((string) $reader['id'], $list['news_readerModule'], 'the news list opens the copied reader');
        self::assertSame(serialize(['', '', (string) $size['id']]), $list['imgSize']);
        self::assertSame(serialize(['300', '200', 'crop']), $reader['imgSize'], 'a mode is no image size');
        self::assertSame('99', $events['cal_readerModule'], 'a reader in another theme stays');
        self::assertSame('5', $list['jumpTo'], 'pages stay');
        self::assertSame((string) $list['id'], $element['module']);
        self::assertSame(serialize(['', '', (string) $size['id']]), $element['size']);
        self::assertSame($element['id'], $nested['pid'], 'the nested element sits in the copied one');
        self::assertSame(serialize(['', '', (string) $size['id']]), $layout['lightboxSize']);
        self::assertSame(serialize([
            ['mod' => (string) $list['id'], 'col' => 'main', 'enable' => '1'],
            ['mod' => 'content-' . $element['id'], 'col' => 'header', 'enable' => '1'],
            ['mod' => '99', 'col' => 'left', 'enable' => '1'],
            ['mod' => '0', 'col' => 'main', 'enable' => '1'],
        ]), $layout['modules']);

        // Review before v1.9.0: neither field carries relation/foreignKey to tl_module.
        self::assertSame(
            // 5.3 stores a root without a module as '' (includeBlankOption): it stays.
            serialize([1 => (string) $reader['id'], 2 => 'content-' . $element['id'], 3 => '99', 4 => '']),
            $this->copyOf('tl_module', 'name', 'Je Root')['rootPageDependentModules'],
        );
        self::assertNotEmpty($this->copyOf('tl_content', 'cteAlias', (string) $element['id']), 'an alias of a theme element follows it');
        self::assertNotEmpty($this->copyOf('tl_content', 'cteAlias', '72'), 'an alias of an article element stays');

        // The originals are untouched.
        self::assertSame('11', $this->tables['tl_module'][10]['news_readerModule']);
        self::assertSame('10', $this->tables['tl_content'][70]['module']);
    }

    public function testTheAnswerNamesWhatWasBentAndWhatPointsOut(): void
    {
        $result = $this->cloner()->clone(1, [], 'k.jones');

        self::assertSame([
            'tl_content.cteAlias'      => 1,
            'tl_content.module'        => 1,
            'tl_content.size'          => 1,
            'tl_layout.lightboxSize'   => 1,
            'tl_layout.modules'        => 1,
            'tl_module.imgSize'        => 1,
            'tl_module.news_readerModule' => 1,
            'tl_module.rootPageDependentModules' => 1,
        ], (array) $result['remapped']);
        self::assertContains('templates', $result['references'], 'the copy uses the same template folder');
    }

    /**
     * Versions show the bent rows: every version and callback comes after the last insert,
     * children first, the theme last.
     */
    public function testVersionsAndCallbacksFollowTheBending(): void
    {
        $this->cloner()->clone(1, [], 'k.jones');

        $lastWrite = max(array_keys(array_filter($this->log, static fn (string $l): bool => str_starts_with($l, 'insert ') || str_starts_with($l, 'update '))));
        $firstVersion = min(array_keys(array_filter($this->log, static fn (string $l): bool => str_starts_with($l, 'version '))));
        self::assertGreaterThan($lastWrite, $firstVersion, 'versions come after the last bent row');
        self::assertStringStartsWith('oncopy tl_theme:', end($this->log));
    }

    public function testASecondCopyCountsOn(): void
    {
        $this->tables['tl_theme'][3] = ['id' => 3, 'tstamp' => 1, 'name' => 'Basis (Kopie)', 'templates' => '', 'screenshot' => null];

        $result = $this->cloner()->clone(1, [], 'k.jones');

        self::assertSame('Basis (Kopie 2)', $this->tables['tl_theme'][$result['id']]['name']);
    }

    public function testATakenNameIsRefusedBeforeAnythingIsWritten(): void
    {
        try {
            $this->cloner()->clone(1, ['name' => 'Anderes'], 'k.jones');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('gibt es schon', $e->getMessage());
        }
        self::assertSame([], $this->log);
    }

    public function testOnlyTheNameIsTakenFromModifications(): void
    {
        $result = $this->cloner()->clone(1, ['name' => 'Relaunch', 'templates' => 'x'], 'k.jones');

        self::assertSame('Relaunch', $this->tables['tl_theme'][$result['id']]['name']);
        self::assertSame(['templates'], $result['ignored_modifications']);
    }
}
