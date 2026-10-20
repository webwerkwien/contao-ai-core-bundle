<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentFindCommand;

/**
 * contao:content:find (v1.10.0): elements by their text, with article, page, root and
 * language. Over a small in-memory tree: two roots (de, en), a page each, an article each.
 */
class ContentFindCommandTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    private const PAGES = [
        1 => ['id' => 1, 'pid' => 0, 'type' => 'root', 'title' => 'Website DE', 'alias' => 'de', 'language' => 'de', 'dns' => 'example.org', 'published' => 1],
        2 => ['id' => 2, 'pid' => 0, 'type' => 'root', 'title' => 'Website EN', 'alias' => 'en', 'language' => 'en', 'dns' => 'example.org', 'published' => 1],
        10 => ['id' => 10, 'pid' => 1, 'type' => 'regular', 'title' => 'Start', 'alias' => 'start', 'language' => '', 'dns' => '', 'published' => 1],
        20 => ['id' => 20, 'pid' => 2, 'type' => 'regular', 'title' => 'Home', 'alias' => 'home', 'language' => '', 'dns' => '', 'published' => 1],
    ];

    private const ARTICLES = [
        100 => ['id' => 100, 'pid' => 10, 'title' => 'Start', 'inColumn' => 'main', 'published' => 1],
        200 => ['id' => 200, 'pid' => 20, 'title' => 'Home', 'inColumn' => 'main', 'published' => 1],
    ];

    /**
     * @param list<array<string, mixed>> $contentRows what the LIKE query returns
     * @param array<int, array<string, mixed>> $nested elements other elements sit in
     */
    private function tester(array $contentRows, array $nested = []): CommandTester
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) use ($contentRows): array {
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

    private const SLIDER_DE = ['id' => 416, 'pid' => 100, 'ptable' => 'tl_article', 'type' => 'rsce_slider', 'headline' => 'a:2:{s:5:"value";s:13:"Das beste CMS";s:4:"unit";s:2:"h2";}', 'text' => '', 'html' => '', 'invisible' => 0];
    private const SLIDER_EN = ['id' => 160, 'pid' => 200, 'ptable' => 'tl_article', 'type' => 'rsce_slider', 'headline' => 'a:2:{s:5:"value";s:14:"A powerful CMS";s:4:"unit";s:2:"h2";}', 'text' => '<p>Built for <strong>editors</strong> &amp; agents.</p>', 'html' => '', 'invisible' => 1];

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
        self::assertStringContainsString('headline LIKE ?', $sql);
        self::assertStringContainsString('html LIKE ?', $sql);
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
        self::assertStringEndsWith(' LIMIT 2', $this->queries[0][0]);
    }

    public function testANestedElementIsTracedToItsArticle(): void
    {
        $inner = ['id' => 500, 'pid' => 499, 'ptable' => 'tl_content', 'type' => 'text', 'headline' => '', 'text' => '<p>Kontakt aufnehmen</p>', 'html' => '', 'invisible' => 0];
        $tester = $this->tester([$inner], [499 => ['ptable' => 'tl_article', 'pid' => 100]]);
        $tester->execute(['query' => 'Kontakt']);
        $hit = self::answer($tester)['results'][0];

        self::assertSame(100, $hit['article']['id']);
        self::assertSame('de', $hit['root']['language']);
    }

    public function testAnElementOfANewsEntryNamesTheEntry(): void
    {
        $news = ['id' => 600, 'pid' => 7, 'ptable' => 'tl_news', 'type' => 'text', 'headline' => '', 'text' => '<p>Wir feiern im Garten</p>', 'html' => '', 'invisible' => 0];
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
}
