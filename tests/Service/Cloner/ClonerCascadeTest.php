<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\CalendarCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\ClonesContentSubtree;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\FaqCategoryCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\GeneratesUniqueAlias;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\NewsArchiveCloner;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\PageCloner;

/**
 * What each cloner owes, and what it deliberately does not.
 *
 * Two findings from the audit on 2026-09-02 live here:
 *
 * **H-5** — `PageCloner` pulled the `tl_content` subtree along, the news and
 * calendar cloners did not. Cloning an archive copied the archive and its news
 * and left every content element behind, counted only the news and answered
 * `ok`, while the command description promises *"including all cascading
 * children"*. The evidence was in this bundle's own prose:
 * `RecordCascadeCollector` says *"tl_content hangs under articles, news and
 * events"* — so deleting a news item took its content with it and cloning one
 * did not bring it along.
 *
 * **H-6** — child aliases were generated but never checked. `Model::save()` does
 * not run the DCA `save_callback`, so Contao's own uniqueness check never ran.
 *
 * 🎯 The interesting assertion is the last one. `PageCloner` must **not** use
 * `uniqueAlias`, because `tl_page.alias` and `tl_article.alias` carry no
 * `eval.unique` — a page alias may repeat across roots and Contao scopes the
 * check by root and domain. Applying H-6 to every cloner would have renamed
 * pages for a clash that is not one. A rule worth having is worth bounding.
 */
class ClonerCascadeTest extends TestCase
{
    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function traitsOf(string $class): array
    {
        return array_values(array_map(
            static fn (string $t): string => substr($t, strrpos($t, '\\') + 1),
            array_keys((new \ReflectionClass($class))->getTraits()),
        ));
    }

    private function sourceOf(string $file): string
    {
        $code   = '';
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Service/Cloner/' . $file);

        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        // Eine Untergrenze, die "gar nichts gelesen" fängt, ohne kleine
        // Schnittstellen-Dateien zu bestrafen — EntityClonerInterface.php hat
        // nach dem Kommentar-Strippen 294 Zeichen und ist völlig in Ordnung.
        self::assertGreaterThan(50, \strlen($code), "$file read as almost nothing");

        return $code;
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function contentCascading(): iterable
    {
        yield 'news archive' => [NewsArchiveCloner::class, 'NewsArchiveCloner.php', 'tl_news'];
        yield 'calendar'     => [CalendarCloner::class, 'CalendarCloner.php', 'tl_calendar_events'];
        yield 'page'         => [PageCloner::class, 'PageCloner.php', 'tl_content'];
    }

    /**
     * @dataProvider contentCascading
     *
     * @param class-string $class
     */
    public function testTheContentSubtreeComesAlong(string $class, string $file, string $ptable): void
    {
        self::assertContains(
            'ClonesContentSubtree',
            $this->traitsOf($class),
            "$file no longer clones the content subtree — H-5 would be back",
        );

        self::assertStringContainsString(
            "cloneContentSubtree(",
            $this->sourceOf($file),
            "$file uses the trait but never calls it",
        );

        self::assertStringContainsString(
            "'" . $ptable . "'",
            $this->sourceOf($file),
            "$file does not name $ptable as the parent table of its content children",
        );
    }

    public function testThereIsOnlyOneImplementationOfContentCloning(): void
    {
        // It existed once, was needed three times, and the two copies that were
        // never written are the bug. A second implementation appearing here is
        // the shape to catch, not the symptom.
        $found = [];

        foreach (glob(__DIR__ . '/../../../src/Service/Cloner/*.php') as $path) {
            $name = basename($path);

            if ('ClonesContentSubtree.php' === $name) {
                continue;
            }

            if (str_contains($this->sourceOf($name), 'function cloneContentRow')) {
                $found[] = $name;
            }
        }

        self::assertSame([], $found, 'content cloning was copied again into: ' . implode(', ', $found));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function uniqueAliasTables(): iterable
    {
        yield 'news'     => [NewsArchiveCloner::class, 'tl_news'];
        yield 'events'   => [CalendarCloner::class, 'tl_calendar_events'];
        yield 'faq'      => [FaqCategoryCloner::class, 'tl_faq'];
    }

    /**
     * @dataProvider uniqueAliasTables
     *
     * @param class-string $class
     */
    public function testTablesWhoseAliasMustBeUniqueGetACheckedOne(string $class, string $table): void
    {
        // All three declare `'unique'=>true, 'doNotCopy'=>true` in Contao 5.3.
        self::assertContains('GeneratesUniqueAlias', $this->traitsOf($class), "$table alias is unchecked again");
    }

    public function testThePageClonerDeliberatelyDoesNotSuffixAliases(): void
    {
        // 🎯 The boundary of H-6. `tl_page.alias` and `tl_article.alias` have no
        // `eval.unique`; Contao scopes them by root and domain in its own
        // callback. Suffixing here would rename a page for a clash that is not
        // one — the mistake a blanket application of the fix would have made.
        self::assertNotContains(
            'GeneratesUniqueAlias',
            $this->traitsOf(PageCloner::class),
            'PageCloner started suffixing aliases that Contao does not require to be unique',
        );
    }
}
