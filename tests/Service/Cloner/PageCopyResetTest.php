<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\PageCopyReset;

/**
 * A cloned page is unpublished and never a main language of its own accord.
 *
 * Until v1.7.3 the cloner copied every `tl_page` field but the alias. Both guides
 * promised "pages and articles come out unpublished, as Contao's copy with subpages
 * does"; only the articles were. On showcase.conpai.eu (2026-10-14) a recursive clone
 * of a whole language tree came out public, and a root cloned without an explicit
 * `"fallback": ""` became a second main language on its domain.
 */
class PageCopyResetTest extends TestCase
{
    /**
     * The definitions as in Contao 5.3 to 6.0 (`contao/dca/tl_page.php`).
     *
     * @return array<string, array<string, mixed>>
     */
    private function dca(): array
    {
        return [
            'published'               => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'boolean', 'default' => false]],
            'fallback'                => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'boolean', 'default' => false]],
            'disableLanguageRedirect' => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'boolean', 'default' => false]],
            'robotsTxt'               => ['eval' => ['doNotCopy' => true], 'sql' => 'text NULL'],
            'language'                => ['eval' => ['doNotCopy' => true], 'sql' => "varchar(64) NOT NULL default ''"],
            'urlPrefix'               => ['eval' => ['doNotCopy' => true], 'sql' => "varchar(128) BINARY NOT NULL default ''"],
            'robots'                  => ['sql' => "varchar(32) NOT NULL default ''"],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedEnglishRoot(): array
    {
        return [
            'id' => 1, 'type' => 'root', 'title' => 'conpai.eu', 'published' => 1, 'fallback' => 1,
            'disableLanguageRedirect' => 1, 'robotsTxt' => "User-agent: *\nDisallow: /", 'language' => 'en',
            'urlPrefix' => '', 'robots' => 'noindex,nofollow',
        ];
    }

    public function testTheFourFieldsGetTheirEmptyValueAsInABackEndCopy(): void
    {
        $this->assertSame(
            ['published' => false, 'fallback' => false, 'disableLanguageRedirect' => false, 'robotsTxt' => null],
            PageCopyReset::values($this->publishedEnglishRoot(), $this->dca()),
        );
    }

    /**
     * `alias` is regenerated, `language` and `urlPrefix` are kept on purpose: a clone
     * into another language sets both in the same call. `robots` has no `doNotCopy`,
     * so a back-end copy keeps it too.
     */
    public function testWhatTheClonerHandlesElsewhereOrKeepsIsNotReset(): void
    {
        $values = PageCopyReset::values($this->publishedEnglishRoot(), $this->dca());

        foreach (['alias', 'language', 'urlPrefix', 'robots', 'title'] as $field) {
            $this->assertArrayNotHasKey($field, $values);
        }
    }

    public function testAColumnTheInstallationLacksIsNotWritten(): void
    {
        $row = $this->publishedEnglishRoot();
        unset($row['disableLanguageRedirect']);

        $this->assertArrayNotHasKey('disableLanguageRedirect', PageCopyReset::values($row, $this->dca()));
    }

    /**
     * Guessing '' for an unknown definition would fail a boolean column in strict
     * SQL mode, the failure FiltersModifications::normaliseFlag() exists for.
     */
    public function testAFieldWithoutADefinitionIsLeftAlone(): void
    {
        $dca = $this->dca();
        unset($dca['robotsTxt']);

        $this->assertArrayNotHasKey('robotsTxt', PageCopyReset::values($this->publishedEnglishRoot(), $dca));
    }

    /**
     * The reset runs for every cloned page and before the operator's modifications, so
     * `{"published": "1"}` or `{"fallback": "1"}` on the root still wins. Without the
     * DCA loaded the reset would find no definition and skip all four in silence.
     */
    public function testTheClonerResetsBeforeTheModificationsWithTheDcaLoaded(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Service/Cloner/PageCloner.php');

        $load      = strpos($source, "Controller::loadDataContainer('tl_page')");
        $reset     = strpos($source, 'PageCopyReset::values($sourceRow');
        $modifying = strpos($source, 'foreach ($modifications as $key => $value)');

        $this->assertNotFalse($load);
        $this->assertNotFalse($reset);
        $this->assertNotFalse($modifying);
        $this->assertLessThan($reset, $load);
        $this->assertLessThan($modifying, $reset);
    }
}
