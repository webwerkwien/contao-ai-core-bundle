<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Language;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LanguageVersions;

/**
 * Language versions as changelanguage links them (v1.11.0), over a real SQLite database
 * shaped like showcase: an English main tree (root 1), a German one (root 76) whose pages
 * point at the English ones, and a French one (root 50).
 */
class LanguageVersionsTest extends TestCase
{
    public static function database(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $db->executeStatement('CREATE TABLE tl_page (id INTEGER PRIMARY KEY, pid INTEGER, sorting INTEGER, type TEXT, title TEXT, alias TEXT, language TEXT, dns TEXT, fallback INTEGER, published INTEGER, languageMain INTEGER)');
        $db->executeStatement('CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER, title TEXT, inColumn TEXT, published INTEGER, languageMain INTEGER)');
        $db->executeStatement('CREATE TABLE tl_news_archive (id INTEGER PRIMARY KEY, jumpTo INTEGER)');
        $db->executeStatement('CREATE TABLE tl_news (id INTEGER PRIMARY KEY, pid INTEGER, headline TEXT, published INTEGER, languageMain INTEGER)');
        $db->executeStatement('CREATE TABLE tl_content (id INTEGER PRIMARY KEY, pid INTEGER, ptable TEXT, type TEXT, headline TEXT, text TEXT, html TEXT, invisible INTEGER)');

        foreach ([
            // id, pid, sorting, type, title, language, fallback, published, languageMain
            [1, 0, 128, 'root', 'Showcase EN', 'en', 1, 1, 0],
            [76, 0, 256, 'root', 'Showcase DE', 'de', 0, 1, 0],
            [50, 0, 384, 'root', 'Showcase FR', 'fr', 0, 1, 0],
            [2, 1, 1, 'regular', 'Home', '', 0, 1, 0],
            [34, 1, 2, 'regular', 'Login', '', 0, 1, 0],
            [77, 76, 1, 'regular', 'Startseite', '', 0, 1, 2],
            [86, 76, 2, 'regular', 'Anmeldung', '', 0, 0, 34],
            [90, 76, 3, 'regular', 'Nicht verknüpft', '', 0, 1, 0],
            [91, 76, 4, 'regular', 'Kaputt', '', 0, 1, 999],
            [51, 50, 1, 'regular', 'Accueil', '', 0, 1, 2],
            // a level deeper: the root is two steps up (review 2026-10-20)
            [3, 2, 1, 'regular', 'Team', '', 0, 1, 0],
            [78, 77, 1, 'regular', 'Team DE', '', 0, 1, 3],
        ] as [$id, $pid, $sorting, $type, $title, $language, $fallback, $published, $main]) {
            $db->insert('tl_page', ['id' => $id, 'pid' => $pid, 'sorting' => $sorting, 'type' => $type, 'title' => $title, 'alias' => '', 'language' => $language, 'dns' => '', 'fallback' => $fallback, 'published' => $published, 'languageMain' => $main]);
        }
        foreach ([[36, 34, 'Login', 0], [102, 86, 'Anmeldung', 36], [40, 2, 'Slider', 0], [41, 77, 'Slider', 40]] as [$id, $pid, $title, $main]) {
            $db->insert('tl_article', ['id' => $id, 'pid' => $pid, 'title' => $title, 'inColumn' => 'main', 'published' => 1, 'languageMain' => $main]);
        }
        $db->insert('tl_news_archive', ['id' => 5, 'jumpTo' => 2]);
        $db->insert('tl_news_archive', ['id' => 6, 'jumpTo' => 77]);
        $db->insert('tl_news', ['id' => 300, 'pid' => 5, 'headline' => 'Release', 'published' => 1, 'languageMain' => 0]);
        $db->insert('tl_news', ['id' => 301, 'pid' => 6, 'headline' => 'Veröffentlichung', 'published' => 1, 'languageMain' => 300]);

        return $db;
    }

    public function testTheMainPageNamesTheVersionsThatPointAtIt(): void
    {
        $found = (new LanguageVersions(self::database()))->of('tl_page', 2);

        self::assertSame(['id' => 2, 'title' => 'Home', 'published' => true, 'language' => 'en', 'root' => 1], $found['record']);
        self::assertTrue($found['linked']);
        self::assertSame(2, $found['main']);
        self::assertSame([
            ['id' => 51, 'title' => 'Accueil', 'published' => true, 'language' => 'fr', 'root' => 50, 'main' => false],
            ['id' => 77, 'title' => 'Startseite', 'published' => true, 'language' => 'de', 'root' => 76, 'main' => false],
        ], $found['versions']);
        self::assertArrayNotHasKey('mainMissing', $found);
    }

    public function testALinkedPageNamesItsMainAndTheOtherVersions(): void
    {
        $found = (new LanguageVersions(self::database()))->of('tl_page', 77);

        self::assertSame('de', $found['record']['language']);
        self::assertSame([[2, 'en', true], [51, 'fr', false]], array_map(static fn (array $v): array => [$v['id'], $v['language'], $v['main']], $found['versions']));
    }

    public function testASubpageTakesTheLanguageOfTheRootAboveItsParent(): void
    {
        $found = (new LanguageVersions(self::database()))->of('tl_page', 78);

        self::assertSame(['id' => 78, 'title' => 'Team DE', 'published' => true, 'language' => 'de', 'root' => 76], $found['record']);
        self::assertSame([['id' => 3, 'title' => 'Team', 'published' => true, 'language' => 'en', 'root' => 1, 'main' => true]], $found['versions']);
    }

    public function testArticlesAreLinkedLikePages(): void
    {
        $found = (new LanguageVersions(self::database()))->of('tl_article', 36);

        self::assertSame([['id' => 102, 'title' => 'Anmeldung', 'published' => true, 'language' => 'de', 'root' => 76, 'main' => false]], $found['versions']);
    }

    public function testAnEntryTakesItsLanguageFromTheArchivesJumpToPage(): void
    {
        $found = (new LanguageVersions(self::database()))->of('tl_news', 301);

        self::assertSame('de', $found['record']['language']);
        self::assertSame([['id' => 300, 'title' => 'Release', 'published' => true, 'language' => 'en', 'root' => 1, 'main' => true]], $found['versions']);
    }

    public function testAnUnlinkedPageHasNoVersions(): void
    {
        $found = (new LanguageVersions(self::database()))->of('tl_page', 90);

        self::assertFalse($found['linked']);
        self::assertSame([], $found['versions']);
    }

    public function testALinkToNothingIsMarked(): void
    {
        $found = (new LanguageVersions(self::database()))->of('tl_page', 91);

        self::assertTrue($found['mainMissing'] ?? false);
        self::assertFalse($found['linked']);
    }

    public function testAMissingRecordIsNull(): void
    {
        self::assertNull((new LanguageVersions(self::database()))->of('tl_page', 4711));
    }

    public function testOtherTablesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LanguageVersions(self::database()))->of('tl_content', 1);
    }

    public function testRootsComeInTheOrderOfTheSiteStructure(): void
    {
        self::assertSame(
            [[1, 'en', true], [76, 'de', false], [50, 'fr', false]],
            array_map(static fn (array $r): array => [$r['id'], $r['language'], $r['fallback']], (new LanguageVersions(self::database()))->roots()),
        );
    }
}
