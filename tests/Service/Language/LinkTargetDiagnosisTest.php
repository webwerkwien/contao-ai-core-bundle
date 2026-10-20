<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Language;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LinkTargetDiagnosis;

/**
 * Why changelanguage offers no article to link to (v1.11.1), over a real SQLite database
 * shaped like showcase: the resource pages "_Resources" (main, 28) and "_Ressourcen" (80,
 * linked to 28) are unpublished on purpose.
 */
class LinkTargetDiagnosisTest extends TestCase
{
    public static function database(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, pid INTEGER, type TEXT, published TEXT, start TEXT, stop TEXT, languageMain INTEGER)");

        $soon = (string) (time() + 86400);
        $past = (string) (time() - 86400);
        foreach ([
            // id, pid, type, published, start, stop, languageMain
            [1, 0, 'root', '1', '', '', 0],
            [76, 0, 'root', '1', '', '', 0],
            [50, 0, 'root', '', '', '', 0],          // an unpublished main root
            [2, 1, 'regular', '1', '', '', 0],       // shown
            [77, 76, 'regular', '', '', '', 2],      // unpublished itself: not asked by changelanguage
            [28, 1, 'regular', '', '', '', 0],       // _Resources, unpublished
            [80, 76, 'regular', '', '', '', 28],     // _Ressourcen
            [30, 1, 'regular', '1', $soon, '', 0],   // not shown yet
            [81, 76, 'regular', '1', '', '', 30],
            [31, 1, 'regular', '1', '', $past, 0],   // no longer shown
            [82, 76, 'regular', '1', '', '', 31],
            [51, 50, 'regular', '1', '', '', 0],     // shown, below an unpublished root
            [83, 76, 'regular', '1', '', '', 51],
            [84, 76, 'regular', '1', '', '', 0],     // not linked
            [85, 76, 'regular', '1', '', '', 999],   // linked to nothing
            [52, 50, 'regular', '', '', '', 0],      // unpublished below an unpublished root
            [90, 76, 'regular', '1', '', '', 52],
            // at the start/stop boundary for a clock at self::NOW
            [40, 1, 'regular', '1', (string) self::NOW, '', 0],         // start = now: hidden
            [91, 76, 'regular', '1', '', '', 40],
            [41, 1, 'regular', '1', (string) (self::NOW - 60), '', 0],  // started a minute ago: shown
            [92, 76, 'regular', '1', '', '', 41],
            [42, 1, 'regular', '1', '', (string) (self::NOW + 60), 0],  // stop = now + 60: hidden
            [93, 76, 'regular', '1', '', '', 42],
            [43, 1, 'regular', '1', '', (string) (self::NOW + 120), 0], // stops later: shown
            [94, 76, 'regular', '1', '', '', 43],
        ] as [$id, $pid, $type, $published, $start, $stop, $main]) {
            $db->insert('tl_page', ['id' => $id, 'pid' => $pid, 'type' => $type, 'published' => $published, 'start' => $start, 'stop' => $stop, 'languageMain' => $main]);
        }

        $db->executeStatement('CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER)');
        foreach ([98 => 80, 99 => 77, 100 => 80, 101 => 83] as $id => $pid) {
            $db->insert('tl_article', ['id' => $id, 'pid' => $pid]);
        }

        return $db;
    }

    /** A clock on a full minute, in the future of every real run's fixture rows. */
    private const NOW = 1_900_000_020;

    public function testArticlesAreSplitIntoClosableAndUnlinkableWithTheirReason(): void
    {
        $split = (new LinkTargetDiagnosis(self::database()))->forArticles([98, 99, 100, 101]);

        self::assertSame([99], $split['closable']);
        self::assertSame([[98, 80], [100, 80], [101, 83]], array_map(static fn (array $a): array => [$a['id'], $a['page']], $split['unlinkable']));
        self::assertStringContainsString('page 28 is unpublished', $split['unlinkable'][0]['reason']);
        self::assertStringContainsString('website root 50 is unpublished', $split['unlinkable'][2]['reason']);
    }

    public function testNoArticlesNoQuery(): void
    {
        self::assertSame(['closable' => [], 'unlinkable' => []], (new LinkTargetDiagnosis(self::database()))->forArticles([]));
    }

    public function testBothThePageAndItsRootAreNamedWhenBothAreTheReason(): void
    {
        $why = (string) self::why(90);

        self::assertStringContainsString('page 52 is unpublished and its website root 50 is unpublished', $why);
        self::assertStringContainsString('once both are published', $why);
    }

    /**
     * At the boundary, as PageFinder::addPublishingConditions() draws it: start < now,
     * stop > now + 60, minutes floored (review 2026-10-20: ±1 day missed an off-by-one).
     */
    public function testTheStartAndStopBoundaryIsChangelanguages(): void
    {
        $diagnosis = new LinkTargetDiagnosis(self::database(), static fn (): int => self::NOW + 30);

        self::assertStringContainsString('page 40 is not shown yet', (string) $diagnosis->forArticlePage(91));
        self::assertNull($diagnosis->forArticlePage(92));
        self::assertStringContainsString('page 42 is no longer shown', (string) $diagnosis->forArticlePage(93));
        self::assertNull($diagnosis->forArticlePage(94));
    }

    private static function why(int $page): ?string
    {
        return (new LinkTargetDiagnosis(self::database()))->forArticlePage($page);
    }

    public function testAnUnpublishedCounterpartIsNamed(): void
    {
        $why = (string) self::why(80);

        self::assertStringContainsString('Page 80 is linked to page 28 of the main language, but page 28 is unpublished', $why);
        self::assertStringContainsString('in the back end as well', $why);
        self::assertStringNotContainsString('link the page first', $why);
    }

    public function testStartAndStopCountLikeUnpublished(): void
    {
        self::assertStringContainsString('page 30 is not shown yet', (string) self::why(81));
        self::assertStringContainsString('page 31 is no longer shown', (string) self::why(82));
    }

    public function testAnUnpublishedRootOfTheCounterpartIsNamed(): void
    {
        self::assertStringContainsString('its website root 50 is unpublished', (string) self::why(83));
    }

    public function testAnUnlinkedPageSaysToLinkThePageFirst(): void
    {
        self::assertStringContainsString('Page 84 is not linked', (string) self::why(84));
    }

    public function testALinkToNothingIsSaid(): void
    {
        self::assertStringContainsString('page 999, which does not exist', (string) self::why(85));
    }

    /**
     * The article's own page is not asked about, as changelanguage does not ask: 77 is
     * unpublished, its counterpart 2 is shown — no known reason.
     */
    public function testAShownCounterpartGivesNoReason(): void
    {
        self::assertNull(self::why(77));
    }

    public function testAMissingPageGivesNoReason(): void
    {
        self::assertNull(self::why(4711));
    }
}
