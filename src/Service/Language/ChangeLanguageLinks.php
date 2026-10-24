<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Language;

use Contao\Database;
use Contao\PageModel;
use Terminal42\ChangeLanguage\PageFinder;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;

/**
 * Which field links a record to its translation, and which targets are valid — as
 * `terminal42/contao-changelanguage` defines both (v1.4.0).
 *
 * Only registered when the package is installed. What it does not do is enforce the
 * package's rules on writes: those run as the package's own callbacks since v1.4.0
 * (duplicate links refused on save, invalid ones reset on copy, cut and delete). This
 * class answers the two questions the back end answers with its widgets — which field,
 * and which targets the field offers — so a link written through the CLI is one the
 * back end could have written.
 *
 * | Table                                            | Field          | Valid targets                         |
 * |--------------------------------------------------|----------------|---------------------------------------|
 * | `tl_page`, a page                                | `languageMain` | a page in the tree of the main language (PageFinder) |
 * | `tl_page`, a fallback root                       | `languageRoot` | the package's own options             |
 * | `tl_article`, `tl_news`, `tl_calendar_events`, `tl_faq` | `languageMain` | the package's own options (needs the page or archive linked first) |
 * | `tl_news_archive`, `tl_calendar`, `tl_faq_category` | `master`    | the package's own options             |
 */
class ChangeLanguageLinks
{
    public const TABLES = [
        'tl_page', 'tl_article',
        'tl_news', 'tl_news_archive',
        'tl_calendar_events', 'tl_calendar',
        'tl_faq', 'tl_faq_category',
    ];

    public const ARCHIVES = ['tl_news_archive' => 'tl_news', 'tl_calendar' => 'tl_calendar_events', 'tl_faq_category' => 'tl_faq'];

    public function __construct(
        private readonly OptionsResolver $options,
        private readonly ?LinkTargetDiagnosis $diagnosis = null,
    ) {
    }

    /**
     * The back-end bundle's LanguageTool keeps a copy of this rule for its field
     * permission check (it allows older cores) — change both.
     *
     * @param array<string, mixed> $record
     */
    public function field(string $table, array $record): string
    {
        if (isset(self::ARCHIVES[$table])) {
            return 'master';
        }

        if ('tl_page' === $table && 'root' === ($record['type'] ?? null)) {
            return 'languageRoot';
        }

        return 'languageMain';
    }

    /**
     * Why $target cannot be linked to the record, or null when it can.
     *
     * @param array<string, mixed> $record
     */
    public function refusal(string $table, int $id, array $record, int $target): ?string
    {
        $field = $this->field($table, $record);

        if ('tl_page' === $table && 'languageMain' === $field) {
            return $this->pageRefusal($id, $target);
        }

        if ('languageRoot' === $field && !($record['fallback'] ?? false)) {
            return "Root page {$id} is not a language fallback. changelanguage links fallback roots of different domains with each other (languageRoot); a non-fallback root belongs to the fallback root of its own domain without a link.";
        }

        $values = $this->options->values($table, $field, $record, $id);

        if (null === $values) {
            return \sprintf('The targets for %s.%s could not be read: %s', $table, $field, $this->options->lastError($table, $field) ?? 'no options');
        }

        if (\in_array((string) $target, $values, true)) {
            return null;
        }

        // No article to offer: say which page or root is the reason, not the general rule
        // (v1.11.1 — before, the refusal said "link the page first" for a linked page
        // whose counterpart is unpublished on purpose).
        $cause = [] === $values && 'tl_article' === $table ? $this->diagnosis?->forArticlePage((int) ($record['pid'] ?? 0)) : null;

        $hint = match (true) {
            [] !== $values      => 'Valid targets: ' . implode(', ', \array_slice($values, 0, 20)) . (\count($values) > 20 ? ', …' : '') . '.',
            null !== $cause     => 'There are none: ' . $cause,
            'tl_article' === $table => 'There are none: the article\'s page has to be linked to its page in the main language first (language link --table tl_page), and changelanguage finds that page only when both pages and their roots are published.',
            'master' === $field => 'There are none: the archive\'s jumpTo page has to be linked to a page in the main language, and the archive there must not be the master of another one already.',
            default             => 'There are none: the archive has to be linked to its archive in the main language first (language link --table ' . array_search($table, self::ARCHIVES, true) . ').',
        };

        return \sprintf('%d is not a valid %s for %s:%d, as the back end offers it. %s', $target, $field, $table, $id, $hint);
    }

    private function pageRefusal(int $id, int $target): ?string
    {
        $page = PageModel::findWithDetails($id);

        if (null === $page) {
            return "Page {$id} not found.";
        }

        $master = (new PageFinder())->findMasterRootForPage($page);

        if (null === $master) {
            return "Page {$id} has no tree in a main language: no fallback root for its domain, or none its root is linked to (languageRoot).";
        }

        if ((int) $master->id === (int) $page->rootId) {
            return "Page {$id} is in the tree of the main language itself (root {$master->id}). The pages of the other languages link to it, not the other way round.";
        }

        $masterTree = Database::getInstance()->getChildRecords((int) $master->id, 'tl_page');

        if (!\in_array($target, array_map('intval', $masterTree), true)) {
            return "Page {$target} is not in the tree of the main language (root {$master->id}, {$master->language}).";
        }

        return null;
    }
}
