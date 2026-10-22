<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentFindCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LanguageVersions;
use Webwerkwien\ContaoAiCoreBundle\Tests\Service\Language\LanguageVersionsTest;

/**
 * contao:content:find (v1.10.0): elements by their text, with article, page, root and
 * language. Over a small in-memory tree: two roots (de, en), a page each, an article each.
 */
class ContentFindCommandTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    private const PAGES = [
        1 => ['id' => '1', 'pid' => '0', 'type' => 'root', 'title' => 'Website DE', 'alias' => 'de', 'language' => 'de', 'dns' => 'example.org', 'published' => '1'],
        2 => ['id' => '2', 'pid' => '0', 'type' => 'root', 'title' => 'Website EN', 'alias' => 'en', 'language' => 'en', 'dns' => 'example.org', 'published' => '1'],
        10 => ['id' => '10', 'pid' => '1', 'type' => 'regular', 'title' => 'Start', 'alias' => 'start', 'language' => '', 'dns' => '', 'published' => '1'],
        20 => ['id' => '20', 'pid' => '2', 'type' => 'regular', 'title' => 'Home', 'alias' => 'home', 'language' => '', 'dns' => '', 'published' => '1'],
    ];

    private const ARTICLES = [
        100 => ['id' => '100', 'pid' => '10', 'title' => 'Start', 'inColumn' => 'main', 'published' => '1'],
        200 => ['id' => '200', 'pid' => '20', 'title' => 'Home', 'inColumn' => 'main', 'published' => '1'],
    ];

    /**
     * @param list<array<string, mixed>> $contentRows what the LIKE query returns
     * @param array<int, array<string, mixed>> $nested elements other elements sit in
     */
    private function tester(array $contentRows, array $nested = []): CommandTester
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params = []) use ($contentRows): array {
            if (str_contains($sql, "type = 'root'")) {
                return []; // the roots of the site (v1.11.0) — see the SQLite tests at the end
            }
            $this->queries[] = [$sql, $params];

            return $contentRows;
        });
        $connection->method('fetchAssociative')->willReturnCallback(static function (string $sql, array $params) use ($nested): array|false {
            $id = (int) $params[0];

            return match (true) {
                str_contains($sql, 'FROM tl_page')    => self::PAGES[$id] ?? false,
                str_contains($sql, 'FROM tl_article') => self::ARTICLES[$id] ?? false,
                str_contains($sql, 'FROM tl_content') => $nested[$id] ?? false,
                str_contains($sql, 'FROM tl_news')    => ['id' => $id, 'label' => 'Sommerfest'],
                default                               => false,
            };
        });

        return new CommandTester(new ContentFindCommand($connection));
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(CommandTester $tester): array
    {
        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }

    private const SLIDER_DE = ['id' => '416', 'pid' => '100', 'ptable' => 'tl_article', 'type' => 'rsce_slider', 'headline' => 'a:2:{s:5:"value";s:13:"Das beste CMS";s:4:"unit";s:2:"h2";}', 'text' => '', 'html' => '', 'invisible' => '0'];
    private const SLIDER_EN = ['id' => '160', 'pid' => '200', 'ptable' => 'tl_article', 'type' => 'rsce_slider', 'headline' => 'a:2:{s:5:"value";s:14:"A powerful CMS";s:4:"unit";s:2:"h2";}', 'text' => '<p>Built for <strong>editors</strong> &amp; agents.</p>', 'html' => '', 'invisible' => '1'];

    public function testAnElementComesWithArticlePageRootAndLanguage(): void
    {
        $tester = $this->tester([self::SLIDER_EN]);
        $tester->execute(['query' => 'powerful CMS']);
        $answer = self::answer($tester);

        self::assertSame('ok', $answer['status']);
        self::assertSame(1, $answer['count']);
        self::assertFalse($answer['more']);
        $hit = $answer['results'][0];
        self::assertSame(160, $hit['id']);
        self::assertSame('headline', $hit['field']);
        self::assertSame('A powerful CMS', $hit['snippet'], 'the headline as text, not serialized');
        self::assertTrue($hit['invisible']);
        self::assertSame(['id' => 200, 'title' => 'Home', 'column' => 'main', 'published' => true], $hit['article']);
        self::assertSame(20, $hit['page']['id']);
        self::assertSame(['id' => 2, 'title' => 'Website EN', 'language' => 'en', 'dns' => 'example.org'], $hit['root']);
    }

    public function testTheSnippetIsPlainText(): void
    {
        $tester = $this->tester([self::SLIDER_EN]);
        $tester->execute(['query' => 'editors & agents']);

        self::assertSame('Built for editors & agents.', self::answer($tester)['results'][0]['snippet']);
        self::assertSame('text', self::answer($tester)['results'][0]['field']);
    }

    /**
     * Both forms are searched: as typed, and as Contao stores it (`&amp;`).
     */
    public function testATextWithAnAmpersandIsSearchedEncodedToo(): void
    {
        $tester = $this->tester([]);
        $tester->execute(['query' => 'editors & agents']);

        [$sql, $params] = $this->queries[0];
        self::assertContains('%editors & agents%', $params);
        self::assertContains('%editors &amp; agents%', $params);
        self::assertStringContainsString('LOWER(headline) LIKE LOWER(?)', $sql, 'case-insensitive also on a binary collation');
        self::assertStringContainsString('LOWER(html) LIKE LOWER(?)', $sql);
    }

    /**
     * A text field stores what the back end posts through Input::encodeInput: `(` as
     * `&#40;`, `'` as `&#39;` (Fable review 2026-10-20; htmlspecialchars alone searched
     * `&#039;` and missed it).
     */
    public function testTheFormATextFieldStoresIsSearchedToo(): void
    {
        $tester = $this->tester([]);
        $tester->execute(['query' => "Preise (Michael's)"]);

        self::assertContains('%Preise &#40;Michael&#39;s&#41;%', $this->queries[0][1]);
    }

    /**
     * Found in the stored form only: the headline that holds it, and the snippet as the
     * page shows it.
     */
    public function testAHeadlineStoredEncodedIsFound(): void
    {
        $row = ['id' => '700', 'pid' => '100', 'ptable' => 'tl_article', 'type' => 'headline', 'headline' => 'a:2:{s:5:"value";s:21:"Preise &#40;2026&#41;";s:4:"unit";s:2:"h2";}', 'text' => '<p>Andere Inhalte</p>', 'html' => '', 'invisible' => '0'];
        $tester = $this->tester([$row]);
        $tester->execute(['query' => 'Preise (2026)']);
        $hit = self::answer($tester)['results'][0];

        self::assertSame('headline', $hit['field']);
        self::assertSame('Preise (2026)', $hit['snippet']);
    }

    /**
     * A match only in markup names the field that holds it, not the first one with text.
     */
    public function testAMatchInMarkupNamesItsField(): void
    {
        $row = ['id' => '701', 'pid' => '100', 'ptable' => 'tl_article', 'type' => 'html', 'headline' => 'a:2:{s:5:"value";s:5:"Hallo";s:4:"unit";s:2:"h2";}', 'text' => '', 'html' => '<a href="/kontakt-formular">Schreiben Sie uns</a>', 'invisible' => '0'];
        $tester = $this->tester([$row]);
        $tester->execute(['query' => 'kontakt-formular']);
        $hit = self::answer($tester)['results'][0];

        self::assertSame('html', $hit['field']);
        self::assertSame('Schreiben Sie uns', $hit['snippet']);
    }

    /**
     * The known non-match: "value" sits in every serialized headline's frame, and the LIKE
     * finds it there. That is no hit.
     */
    public function testTheWordValueInAHeadlinesFrameIsNoHit(): void
    {
        $tester = $this->tester([self::SLIDER_DE, self::SLIDER_EN]);
        $tester->execute(['query' => 'value']);
        $answer = self::answer($tester);

        self::assertSame(0, $answer['count']);
        self::assertSame([], $answer['results']);
    }

    public function testARootThatIsNoRootIsRefused(): void
    {
        $tester = $this->tester([self::SLIDER_DE]);
        $tester->execute(['query' => 'CMS', '--root' => '10']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('is not a website root', self::answer($tester)['message']);
        self::assertSame([], $this->queries, 'nothing is searched');
    }

    public function testWildcardsInTheTextAreLiteral(): void
    {
        $tester = $this->tester([]);
        $tester->execute(['query' => '100%_off']);

        self::assertContains('%100\%\_off%', $this->queries[0][1]);
    }

    public function testRootLimitsToOneLanguage(): void
    {
        $tester = $this->tester([self::SLIDER_DE, self::SLIDER_EN]);
        $tester->execute(['query' => 'CMS', '--root' => '1']);
        $answer = self::answer($tester);

        self::assertSame([416], array_column($answer['results'], 'id'));
        self::assertSame('de', $answer['results'][0]['root']['language']);
    }

    public function testMoreSaysThereIsMore(): void
    {
        $tester = $this->tester([self::SLIDER_DE, self::SLIDER_EN]);
        $tester->execute(['query' => 'CMS', '--limit' => '1']);
        $answer = self::answer($tester);

        self::assertSame(1, $answer['count']);
        self::assertTrue($answer['more']);
        self::assertSame(0, $answer['offset']);
        // read in batches since v1.12.0 (--offset), the first from the start
        self::assertStringEndsWith(' LIMIT 200 OFFSET 0', $this->queries[0][0]);
    }

    public function testAnOffsetThatIsNoNumberIsRefusedBeforeAnythingIsSearched(): void
    {
        $tester = $this->tester([self::SLIDER_DE]);
        $tester->execute(['query' => 'CMS', '--offset' => '-3']);

        self::assertSame('error', self::answer($tester)['status']);
        self::assertSame([], $this->queries);
    }

    public function testANestedElementIsTracedToItsArticle(): void
    {
        $inner = ['id' => '500', 'pid' => '499', 'ptable' => 'tl_content', 'type' => 'text', 'headline' => '', 'text' => '<p>Kontakt aufnehmen</p>', 'html' => '', 'invisible' => '0'];
        $tester = $this->tester([$inner], [499 => ['ptable' => 'tl_article', 'pid' => '100']]);
        $tester->execute(['query' => 'Kontakt']);
        $hit = self::answer($tester)['results'][0];

        self::assertSame(100, $hit['article']['id']);
        self::assertSame('de', $hit['root']['language']);
    }

    public function testAnElementOfANewsEntryNamesTheEntry(): void
    {
        $news = ['id' => '600', 'pid' => '7', 'ptable' => 'tl_news', 'type' => 'text', 'headline' => '', 'text' => '<p>Wir feiern im Garten</p>', 'html' => '', 'invisible' => '0'];
        $tester = $this->tester([$news]);
        $tester->execute(['query' => 'Garten']);
        $hit = self::answer($tester)['results'][0];

        self::assertSame(['table' => 'tl_news', 'id' => 7, 'title' => 'Sommerfest'], $hit['parent']);
        self::assertArrayNotHasKey('root', $hit);
    }

    public function testAShortTextIsRefused(): void
    {
        $tester = $this->tester([]);
        $tester->execute(['query' => 'ab']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('error', self::answer($tester)['status']);
        self::assertSame([], $this->queries, 'nothing is searched');
    }

    /**
     * v1.11.0, the showcase case over a real SQLite site (LanguageVersionsTest): the English
     * slider is found by its text, and the hit names the linked German page and article —
     * the chat had claimed there was no other language.
     *
     * @return array<string, mixed>
     */
    private static function onSite(bool $changelanguage, string $query = 'A powerful CMS'): array
    {
        $db = LanguageVersionsTest::database();
        $db->insert('tl_content', ['id' => 160, 'pid' => 40, 'ptable' => 'tl_article', 'type' => 'rsce_slider', 'headline' => 'a:2:{s:5:"value";s:14:"A powerful CMS";s:4:"unit";s:2:"h2";}', 'text' => '', 'html' => '', 'invisible' => 0]);
        $db->insert('tl_content', ['id' => 416, 'pid' => 41, 'ptable' => 'tl_article', 'type' => 'rsce_slider', 'headline' => 'a:2:{s:5:"value";s:25:"Ein leistungsstarkes CMS";s:4:"unit";s:2:"h2";}', 'text' => '', 'html' => '', 'invisible' => 0]);

        $tester = new CommandTester(new ContentFindCommand($db, $changelanguage ? new LanguageVersions($db) : null));
        $tester->execute(['query' => $query]);

        return self::answer($tester);
    }

    /**
     * From the linked side: the German element names the English main version and the
     * French one (review 2026-10-20: only the main side was tested end to end).
     */
    public function testAHitOnTheLinkedSideNamesTheMainAndTheOtherVersions(): void
    {
        $answer = self::onSite(true, 'Ein leistungsstarkes CMS');

        self::assertSame([416], array_column($answer['results'], 'id'));
        $versions = $answer['results'][0]['versions'];
        self::assertSame([[2, 'en', true], [51, 'fr', false]], array_map(static fn (array $v): array => [$v['id'], $v['language'], $v['main']], $versions['page']));
        self::assertSame([[40, 'en', true]], array_map(static fn (array $v): array => [$v['id'], $v['language'], $v['main']], $versions['article']));
    }

    public function testAHitNamesTheLinkedVersionsOfItsPageAndArticle(): void
    {
        $answer = self::onSite(true);

        self::assertSame([160], array_column($answer['results'], 'id'));
        $versions = $answer['results'][0]['versions'];
        self::assertSame([[51, 'fr'], [77, 'de']], array_map(static fn (array $v): array => [$v['id'], $v['language']], $versions['page']));
        self::assertSame([[41, 'de']], array_map(static fn (array $v): array => [$v['id'], $v['language']], $versions['article']));
        self::assertSame(['en', 'de', 'fr'], array_column($answer['roots'], 'language'));
        self::assertArrayNotHasKey('versionsNote', $answer);
    }

    /**
     * v1.12.0, --offset over a real SQLite site: pages of results, across the batch
     * boundary of 200 rows, and with --root counting only that root's results.
     *
     * @return array<string, mixed>
     */
    private static function paged(array $options, int $english = 5, int $german = 0): array
    {
        $db = LanguageVersionsTest::database();
        $id = 1000;
        foreach ([[40, $english], [41, $german]] as [$article, $count]) {
            for ($i = 0; $i < $count; ++$i) {
                $db->insert('tl_content', ['id' => ++$id, 'pid' => $article, 'ptable' => 'tl_article', 'type' => 'text', 'headline' => '', 'text' => "<p>paging probe $i</p>", 'html' => '', 'invisible' => 0]);
            }
        }
        $tester = new CommandTester(new ContentFindCommand($db));
        $tester->execute(['query' => 'paging probe'] + $options);

        return self::answer($tester);
    }

    public function testTheOffsetPagesThroughTheResults(): void
    {
        $first = self::paged(['--limit' => '2']);
        $second = self::paged(['--limit' => '2', '--offset' => '2']);
        $last = self::paged(['--limit' => '2', '--offset' => '4']);
        $beyond = self::paged(['--limit' => '2', '--offset' => '5']);

        self::assertSame([[1001, 1002], true], [array_column($first['results'], 'id'), $first['more']]);
        self::assertSame([[1003, 1004], true], [array_column($second['results'], 'id'), $second['more']]);
        self::assertSame([[1005], false], [array_column($last['results'], 'id'), $last['more']]);
        self::assertSame([[], false, 5], [$beyond['results'], $beyond['more'], $beyond['offset']]);
    }

    public function testPagingCrossesTheBatchBoundary(): void
    {
        $answer = self::paged(['--limit' => '10', '--offset' => '198'], 205);

        self::assertSame(range(1199, 1205), array_column($answer['results'], 'id'));
        self::assertFalse($answer['more']);
    }

    public function testWithARootTheOffsetCountsOnlyItsResults(): void
    {
        // 3 English (root 1), then 4 German (root 76); page 2 of the German ones
        $answer = self::paged(['--root' => '76', '--limit' => '2', '--offset' => '2'], 3, 4);

        self::assertSame([1006, 1007], array_column($answer['results'], 'id'));
        self::assertFalse($answer['more']);
    }

    public function testWithoutChangelanguageTheAnswerSaysVersionsCannotBeListed(): void
    {
        $answer = self::onSite(false);

        self::assertArrayNotHasKey('versions', $answer['results'][0]);
        self::assertSame([1, 76, 50], array_column($answer['roots'], 'id'));
        self::assertStringContainsString('do not say there is none', $answer['versionsNote']);
    }
}
