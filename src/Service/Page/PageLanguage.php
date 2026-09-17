<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Page;

/**
 * The language a page stores: a root its own, every other page none.
 *
 * `tl_page.language` is only in the root palette; Contao takes a page's language from
 * its root at runtime, and a page created in the back end stores an empty value. Until
 * v0.21.1 `contao:page:create` wrote its `--language` default into every page, and the
 * cloner carried it along (ConpAI 1.0, Nr. 62). See PageLanguageTest.
 */
final class PageLanguage
{
    public static function forType(string $type, string $language): string
    {
        return 'root' === $type ? $language : '';
    }
}
