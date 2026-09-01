<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\MissingBundleHint;

/**
 * "DCA not found" is true and points at the wrong thing.
 *
 * On wienerwandern.at, `news list` answered *DCA not found or empty for table:
 * tl_news*. Correct — and `contao/news-bundle` is simply not installed there.
 * A reader sees "DCA not found" and starts looking for a broken data container,
 * when the answer is that an optional extension was never added.
 *
 * 🎯 **This is the same shape as the mistyped session name fixed the same day:
 * an answer that is accurate and still sends the reader in the wrong
 * direction.** Nothing is wrong, so there is nothing to find, so the search
 * goes on for a while.
 *
 * The distinction the hint has to keep is between "the bundle is absent" and
 * "the bundle is here and the DCA still did not load" — those are different
 * problems, and collapsing them would rebuild the fault at one remove.
 */
class MissingBundleHintTest extends TestCase
{
    public function testATableFromAnAbsentBundleNamesThePackage(): void
    {
        $hint = MissingBundleHint::for('tl_news', static fn (string $class): bool => false);

        self::assertNotNull($hint);
        self::assertStringContainsString('contao/news-bundle', $hint);
    }

    public function testTheHintSaysHowToInstallIt(): void
    {
        $hint = MissingBundleHint::for('tl_news', static fn (string $class): bool => false);

        self::assertStringContainsString('composer require', (string) $hint);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function optionalTables(): iterable
    {
        yield 'news'            => ['tl_news', 'contao/news-bundle'];
        yield 'news archive'    => ['tl_news_archive', 'contao/news-bundle'];
        yield 'calendar'        => ['tl_calendar_events', 'contao/calendar-bundle'];
        yield 'faq'             => ['tl_faq', 'contao/faq-bundle'];
        yield 'comments'        => ['tl_comments', 'contao/comments-bundle'];
        yield 'newsletter'      => ['tl_newsletter_channel', 'contao/newsletter-bundle'];
    }

    /**
     * @dataProvider optionalTables
     */
    public function testEveryOptionalTableIsCovered(string $table, string $package): void
    {
        $hint = MissingBundleHint::for($table, static fn (string $class): bool => false);

        self::assertNotNull($hint, $table.' has no hint');
        self::assertStringContainsString($package, $hint);
    }

    public function testAnInstalledBundleGetsNoHint(): void
    {
        /* The bundle is there and the DCA still did not load. That is a
           different problem, and naming the package would send the reader off
           to install something they already have. */
        $hint = MissingBundleHint::for('tl_news', static fn (string $class): bool => true);

        self::assertNull($hint);
    }

    public function testACoreTableGetsNoHint(): void
    {
        /* tl_page ships with Contao. A missing DCA there is a real fault and
           must not be dressed up as a missing extension. */
        self::assertNull(MissingBundleHint::for('tl_page', static fn (string $class): bool => false));
    }

    public function testAnUnknownTableGetsNoHint(): void
    {
        self::assertNull(MissingBundleHint::for('tl_ww_buchung', static fn (string $class): bool => false));
    }

    public function testTheDefaultDetectorAnswersForThisInstallation(): void
    {
        /* Without an injected detector it asks class_exists, so the result
           describes the installation the test runs on rather than a fixture.
           Contao's own model classes are on the classpath here, so the hint
           must stay silent — a test that passed either way would prove
           nothing. */
        self::assertNull(MissingBundleHint::for('tl_news'));
    }
}
