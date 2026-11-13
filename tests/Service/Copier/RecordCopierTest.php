<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Copier;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\RecordCopier;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * A content element or an article copied against a small in-memory database, with
 * `DC_Table::copy()` and `copyChildren()` (5.7) as the reference: every field, the
 * DCA's resets, `markAsCopy` on the copied record only, children by `ctable` and —
 * for tl_content — by pid and ptable, oncopy for each child after its own children
 * and for the record last.
 */
class RecordCopierTest extends TestCase
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var list<string> */
    private array $log = [];

    private int $nextId = 100;

    protected function setUp(): void
    {
        $varchar = "varchar(255) NOT NULL default ''";
        $int     = 'int(10) unsigned NOT NULL default 0';

        $GLOBALS['TL_LANG']['MSC']['copyOf'] = '%s (Kopie)';
        $GLOBALS['TL_DCA'] = [
            'tl_article' => [
                'config' => ['ptable' => 'tl_page', 'ctable' => ['tl_content'], 'markAsCopy' => 'title'],
                'fields' => [
                    'pid'       => ['sql' => $int],
                    'sorting'   => ['sql' => $int],
                    'title'     => ['sql' => $varchar],
                    'alias'     => ['eval' => ['doNotCopy' => true], 'sql' => $varchar],
                    'author'    => ['default' => static fn (): int => 1, 'eval' => ['doNotCopy' => true], 'sql' => $int],
                    'published' => ['default' => false, 'eval' => ['doNotCopy' => true], 'sql' => ['type' => 'boolean', 'default' => false]],
                    'cssID'     => ['sql' => $varchar],
                ],
            ],
            'tl_content' => [
                'config' => ['dynamicPtable' => true, 'ctable' => ['tl_content'], 'markAsCopy' => 'headline'],
                'fields' => [
                    'pid'       => ['sql' => $int],
                    'ptable'    => ['sql' => $varchar],
                    'sorting'   => ['sql' => $int],
                    'type'      => ['sql' => $varchar],
                    'headline'  => ['inputType' => 'inputUnit', 'sql' => 'text NULL'],
                    'cssID'     => ['sql' => $varchar],
                    'invisible' => ['sql' => ['type' => 'boolean', 'default' => false]],
                ],
            ],
        ];

        $headline = static fn (string $text): string => serialize(['unit' => 'h2', 'value' => $text]);

        $this->tables = [
            'tl_article' => [
                7 => ['id' => 7, 'pid' => 3, 'tstamp' => 1, 'sorting' => 128, 'title' => 'Team', 'alias' => 'team', 'author' => 4, 'published' => '1', 'cssID' => 'a:2:{i:0;s:4:"team";i:1;s:0:"";}'],
            ],
            'tl_content' => [
                // Article 7: an accordion (40) holding two elements, one of them holding one.
                40 => ['id' => 40, 'pid' => 7, 'ptable' => 'tl_article', 'tstamp' => 1, 'sorting' => 128, 'type' => 'accordion', 'headline' => $headline('Fragen'), 'cssID' => 'a:2:{i:0;s:3:"faq";i:1;s:4:"wide";}', 'invisible' => '1'],
                41 => ['id' => 41, 'pid' => 40, 'ptable' => 'tl_content', 'tstamp' => 1, 'sorting' => 256, 'type' => 'text', 'headline' => $headline('Zweite'), 'cssID' => '', 'invisible' => '0'],
                42 => ['id' => 42, 'pid' => 40, 'ptable' => 'tl_content', 'tstamp' => 1, 'sorting' => 128, 'type' => 'element_group', 'headline' => $headline('Erste'), 'cssID' => '', 'invisible' => '0'],
                43 => ['id' => 43, 'pid' => 42, 'ptable' => 'tl_content', 'tstamp' => 1, 'sorting' => 128, 'type' => 'text', 'headline' => $headline(''), 'cssID' => '', 'invisible' => '0'],
                44 => ['id' => 44, 'pid' => 7, 'ptable' => 'tl_article', 'tstamp' => 1, 'sorting' => 256, 'type' => 'text', 'headline' => $headline(''), 'cssID' => '', 'invisible' => '0'],
                // Same pid as article 7, another parent table: not the article's.
                45 => ['id' => 45, 'pid' => 7, 'ptable' => 'tl_news', 'tstamp' => 1, 'sorting' => 64, 'type' => 'text', 'headline' => $headline('News'), 'cssID' => '', 'invisible' => '0'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    private function copier(?TranslatorInterface $translator = null): RecordCopier
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');

        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params): array|false {
            return $this->tables[$this->tableOf($sql)][(int) $params[0]] ?? false;
        });

        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params): array {
            $rows = array_values(array_filter(
                $this->tables[$this->tableOf($sql)] ?? [],
                static fn (array $row): bool => (int) $row['pid'] === (int) $params[0]
                    && (!str_contains($sql, 'ptable') || $row['ptable'] === $params[1]),
            ));

            // The rows come in the order the query asks for and in none other: the
            // fixture lists 41 before 42, the back end shows 42 first.
            if (str_ends_with($sql, 'ORDER BY sorting, id')) {
                usort($rows, static fn (array $a, array $b): int => [$a['sorting'] ?? 0, $a['id']] <=> [$b['sorting'] ?? 0, $b['id']]);
            }

            return $rows;
        });

        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            // Every name quoted: tl_layout has a column called `rows`.
            self::assertMatchesRegularExpression('/^`\w+`$/', $table);
            $table = trim($table, '`');
            $row   = [];
            foreach ($data as $column => $value) {
                self::assertMatchesRegularExpression('/^`\w+`$/', (string) $column);
                $row[trim((string) $column, '`')] = $value;
            }

            $id = $this->nextId++;
            $this->tables[$table][$id] = ['id' => $id] + $row;
            $this->log[] = "insert $table:$id";

            return 1;
        });

        $connection->method('lastInsertId')->willReturnCallback(fn (): int => $this->nextId - 1);

        $versions = $this->createMock(VersionManager::class);
        $versions->method('createInitialVersion')->willReturnCallback(function (string $table, int $id): int {
            $this->log[] = "version $table:$id";

            return 0;
        });

        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('copy')->willReturnCallback(function (string $table, int $newId, int $sourceId): void {
            $this->log[] = "oncopy $table:$newId<-$sourceId";
        });

        return new RecordCopier($connection, $this->createMock(ContaoFramework::class), $versions, $callbacks, $translator);
    }

    /**
     * Contao's translator with the copy mark of a few languages; any other key or
     * language answers with the key, as Contao's catalogue does for a missing one.
     */
    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters, ?string $domain, ?string $locale): string {
            $marks = ['en' => '%s (copy)', 'fr' => '%s (copie)'];

            return 'MSC.copyOf' === $id && 'contao_default' === $domain ? ($marks[$locale] ?? $id) : $id;
        });

        return $translator;
    }

    public function testTheMarkComesInTheGivenLanguage(): void
    {
        $this->copier($this->translator())->copy('tl_article', 7, ['pid' => 3, 'sorting' => 192], 'k.editor', 'fr');

        self::assertSame('Team (copie)', $this->tables['tl_article'][100]['title']);
    }

    public function testALanguageWithoutTheMarkKeepsTheLoadedOne(): void
    {
        $this->copier($this->translator())->copy('tl_article', 7, ['pid' => 3, 'sorting' => 192], 'k.editor', 'xx');

        self::assertSame('Team (Kopie)', $this->tables['tl_article'][100]['title']);
    }

    public function testWithoutALanguageTheLoadedMarkIsUsed(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $this->copier($translator)->copy('tl_article', 7, ['pid' => 3, 'sorting' => 192], 'k.editor', null);

        self::assertSame('Team (Kopie)', $this->tables['tl_article'][100]['title']);
    }

    private function tableOf(string $sql): string
    {
        preg_match('/FROM `(tl_[a-z_]+)`/', $sql, $match);

        return $match[1] ?? '';
    }

    public function testAnElementWithoutChildren(): void
    {
        $result = $this->copier()->copy('tl_content', 44, ['pid' => 9, 'ptable' => 'tl_article', 'sorting' => 64], 'k.editor');

        self::assertSame(['id' => 100, 'children' => 0, 'notCopied' => []], $result);
        self::assertSame(['insert tl_content:100', 'version tl_content:100', 'oncopy tl_content:100<-44'], $this->log);

        $copy = $this->tables['tl_content'][100];
        self::assertSame([9, 'tl_article', 64, 'text'], [$copy['pid'], $copy['ptable'], $copy['sorting'], $copy['type']]);
        // An empty headline is not marked, as in Contao.
        self::assertSame(serialize(['unit' => 'h2', 'value' => '']), $copy['headline']);
    }

    public function testAnElementTakesEveryFieldAndItsNestedElements(): void
    {
        $result = $this->copier()->copy('tl_content', 40, ['pid' => 7, 'ptable' => 'tl_article', 'sorting' => 192], 'k.editor');

        self::assertSame(['id' => 100, 'children' => 3, 'notCopied' => []], $result);

        $copy = $this->tables['tl_content'][100];
        // The fields an editor may not change come along, as in the back end.
        self::assertSame('a:2:{i:0;s:3:"faq";i:1;s:4:"wide";}', $copy['cssID']);
        self::assertSame('1', $copy['invisible']);
        self::assertSame(['unit' => 'h2', 'value' => 'Fragen (Kopie)'], unserialize($copy['headline']));

        // Children in `sorting, id` order, below the copy, ptable kept, not marked.
        self::assertSame([100, 'tl_content', 128], [$this->tables['tl_content'][101]['pid'], $this->tables['tl_content'][101]['ptable'], $this->tables['tl_content'][101]['sorting']]);
        self::assertSame(['unit' => 'h2', 'value' => 'Erste'], unserialize($this->tables['tl_content'][101]['headline']));
        self::assertSame(101, $this->tables['tl_content'][102]['pid']);
        self::assertSame(100, $this->tables['tl_content'][103]['pid']);
        self::assertSame(['unit' => 'h2', 'value' => 'Zweite'], unserialize($this->tables['tl_content'][103]['headline']));

        // oncopy for each child after its own children, the copied element last.
        self::assertSame([
            'oncopy tl_content:102<-43',
            'oncopy tl_content:101<-42',
            'oncopy tl_content:103<-41',
            'oncopy tl_content:100<-40',
        ], array_values(array_filter($this->log, static fn (string $line): bool => str_starts_with($line, 'oncopy'))));
        self::assertCount(4, array_filter($this->log, static fn (string $line): bool => str_starts_with($line, 'version')));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function copiesIntoTheirOwnTree(): iterable
    {
        // source, the parent inside its own tree, its children
        yield 'into itself' => [42, 42, 1];
        yield 'into one of its elements' => [40, 42, 3];
    }

    /**
     * Contao pastes a copy into its own tree and leaves the new record out
     * (`copyChildren()`: "Exclude the duplicated record itself"). Here the tree is
     * read before the first row is written, so the copy cannot meet itself.
     *
     * @dataProvider copiesIntoTheirOwnTree
     */
    public function testACopyInsideItsOwnTreeDoesNotCopyItself(int $source, int $into, int $children): void
    {
        $result = $this->copier()->copy('tl_content', $source, ['pid' => $into, 'ptable' => 'tl_content', 'sorting' => 512], 'k.editor');

        self::assertSame($children, $result['children']);
        self::assertCount(6 + 1 + $children, $this->tables['tl_content']);
        self::assertSame($into, $this->tables['tl_content'][100]['pid']);
    }

    public function testAnArticleWithItsElements(): void
    {
        $result = $this->copier()->copy('tl_article', 7, ['pid' => 3, 'sorting' => 192, 'author' => 5], 'k.editor');

        self::assertSame(100, $result['id']);
        // 40 with its three, and 44; never 45, which belongs to news entry 7.
        self::assertSame(5, $result['children']);
        // The author is not the source's either: Contao gives the copy to the acting user.
        self::assertSame(['alias', 'author', 'published'], $result['notCopied']);

        $copy = $this->tables['tl_article'][100];
        self::assertSame('Team (Kopie)', $copy['title']);
        self::assertSame('', $copy['alias']);
        // A boolean reset is written as an integer: strict SQL refuses '' in a tinyint.
        self::assertSame(0, $copy['published']);
        self::assertSame(5, $copy['author']);
        self::assertSame('a:2:{i:0;s:4:"team";i:1;s:0:"";}', $copy['cssID']);

        $elements = array_filter($this->tables['tl_content'], static fn (array $row): bool => 100 === $row['pid'] && 'tl_article' === $row['ptable']);
        self::assertCount(2, $elements);
        foreach ($elements as $element) {
            self::assertNotSame('News', unserialize($element['headline'])['value']);
        }

        self::assertSame('oncopy tl_article:100<-7', end($this->log));
    }

    public function testTheCopiedRecordTakesTheColumnsOfItsDcaItsChildrenEveryColumn(): void
    {
        // A column an extension added without a DCA field.
        $this->tables['tl_content'][40]['legacy'] = 'x';
        $this->tables['tl_content'][42]['legacy'] = 'y';

        $this->copier()->copy('tl_content', 40, ['pid' => 7, 'ptable' => 'tl_article', 'sorting' => 192], 'k.editor');

        self::assertArrayNotHasKey('legacy', $this->tables['tl_content'][100]);
        self::assertSame('y', $this->tables['tl_content'][101]['legacy']);
    }

    public function testATableTheDcaMarksNotCopyableIsRefused(): void
    {
        $GLOBALS['TL_DCA']['tl_article']['config']['notCopyable'] = true;

        try {
            $this->copier()->copy('tl_article', 7, ['pid' => 3, 'sorting' => 192], 'k.editor');
            self::fail('a table marked notCopyable was copied');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not copyable', $e->getMessage());
        }

        self::assertSame([], $this->log);
    }

    public function testAnOverrideOfTheMarkedFieldWins(): void
    {
        $this->copier()->copy('tl_article', 7, ['pid' => 3, 'sorting' => 192, 'title' => 'Mitarbeiter'], 'k.editor');

        self::assertSame('Mitarbeiter', $this->tables['tl_article'][100]['title']);
    }

    public function testATableWithDoNotCopyRecordsStaysBehind(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['config']['doNotCopyRecords'] = true;

        $result = $this->copier()->copy('tl_article', 7, ['pid' => 3, 'sorting' => 192], 'k.editor');

        self::assertSame(0, $result['children']);
    }

    public function testAMissingSourceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->copier()->copy('tl_content', 999, ['pid' => 7, 'ptable' => 'tl_article', 'sorting' => 1], 'k.editor');
    }
}
