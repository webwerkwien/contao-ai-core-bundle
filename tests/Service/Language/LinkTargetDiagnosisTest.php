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
        ] as [$id, $pid, $type, $published, $start, $stop, $main]) {
            $db->insert('tl_page', ['id' => $id, 'pid' => $pid, 'type' => $type, 'published' => $published, 'start' => $start, 'stop' => $stop, 'languageMain' => $main]);
        }

        return $db;
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
