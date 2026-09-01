<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

/**
 * Why a table has no DCA, when the reason is that an extension is not installed.
 *
 * `DCA not found or empty for table: tl_news` is a true sentence and it points
 * at the wrong thing. On wienerwandern.at (2026-09-01) it was the answer to
 * `news list`, and `contao/news-bundle` is simply not installed there — nothing
 * was broken, so a reader following the message had nothing to find.
 *
 * 🎯 **Same shape as the mistyped session name fixed the same morning: an
 * answer that is accurate and still sends the reader in the wrong direction.**
 * The cure is the same too — say which of the possible causes this actually is.
 *
 * ## What it will not do
 *
 * It stays silent when the bundle *is* installed. "The DCA did not load" with
 * the bundle present is a different problem, and answering it with "install the
 * package" would rebuild the original fault one step further along.
 *
 * It also stays silent for core tables. A missing DCA for `tl_page` is a real
 * fault and must not be dressed up as a missing extension.
 */
final class MissingBundleHint
{
    /**
     * Optional Contao bundles, by the tables they bring.
     *
     * Keyed on the model class rather than the package name, because that is
     * what can be asked at runtime — the package name is only there to be
     * printed. Same `class_exists` check the bundle already uses to decide
     * which command services to register (see ContaoAiCoreBundle), so the two
     * answer from the same evidence.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private const OPTIONAL_BUNDLES = [
        'news' => [
            'contao/news-bundle',
            \Contao\NewsModel::class,
            ['tl_news', 'tl_news_archive', 'tl_news_feed'],
        ],
        'calendar' => [
            'contao/calendar-bundle',
            \Contao\CalendarModel::class,
            ['tl_calendar', 'tl_calendar_events', 'tl_calendar_feed'],
        ],
        'faq' => [
            'contao/faq-bundle',
            \Contao\FaqModel::class,
            ['tl_faq', 'tl_faq_category'],
        ],
        'comments' => [
            'contao/comments-bundle',
            \Contao\CommentsModel::class,
            ['tl_comments', 'tl_comments_notify'],
        ],
        'newsletter' => [
            'contao/newsletter-bundle',
            \Contao\NewsletterChannelModel::class,
            ['tl_newsletter', 'tl_newsletter_channel', 'tl_newsletter_recipients', 'tl_newsletter_deny_list'],
        ],
    ];

    /**
     * A sentence naming the missing package, or null when that is not the cause.
     *
     * @param callable(string):bool|null $isInstalled Class detector; defaults to
     *        class_exists. Injected only so the tests can describe an
     *        installation other than the one they run on.
     */
    public static function for(string $table, ?callable $isInstalled = null): ?string
    {
        $isInstalled ??= static fn (string $class): bool => class_exists($class);

        foreach (self::OPTIONAL_BUNDLES as [$package, $model, $tables]) {
            if (!\in_array($table, $tables, true)) {
                continue;
            }

            if ($isInstalled($model)) {
                return null;
            }

            return \sprintf(
                '%s belongs to %s, which is not installed on this site — so the table has no DCA and nothing is wrong with it. '
                .'Install with: composer require %s',
                $table,
                $package,
                $package,
            );
        }

        return null;
    }
}
