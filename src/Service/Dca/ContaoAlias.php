<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\Controller;
use Contao\System;

/**
 * The alias Contao's own `save_callback` generates for a record that is about to be
 * created.
 *
 * `Model::save()` runs no DCA callback, so until v0.16.0 the create commands made the
 * alias themselves with `StringUtil::generateAlias()`. Contao's callbacks —
 * `tl_article::generateAlias`, `tl_news::…`, `tl_calendar_events::…`,
 * `tl_newsletter::…`, and whatever an extension registers under that name — use the
 * `contao.slug` service with the options of the page the record belongs to: its
 * language and `validAliasCharacters`. Measured on 2026-09-16: `Über uns` became
 * `über-uns` through the bundle and `uber-uns-kopie` through Contao.
 *
 * The callback is called with an empty value, as the back end does when the field is
 * left blank, and a data container that answers `activeRecord` with the record and
 * `id` with 0 — the record has no id yet, and `id != 0` excludes nothing from the
 * duplicate check.
 *
 * ⚠️ Not for `tl_page`: `PageUrlListener::generateAlias()` loads the page by its id
 * (`findWithDetails()`), so it needs a saved record. PageUrlGuard handles pages.
 */
final class ContaoAlias
{
    /**
     * @param array<string, mixed> $record the fields the record will be created with
     *
     * @return string|null the alias, or null when the field has no `generateAlias` callback
     */
    public static function generate(string $table, array $record, string $field = 'alias'): ?string
    {
        // The create commands call this inside the array they hand to preparedFields(),
        // before anything has loaded the DCA. Without this line the callback was never
        // found and every create fell back to the old slug — caught live on c5, not by
        // the tests, which seed the DCA themselves.
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        foreach ($GLOBALS['TL_DCA'][$table]['fields'][$field]['save_callback'] ?? [] as $callback) {
            if (!\is_array($callback) || !\is_string($callback[0] ?? null) || 'generateAlias' !== ($callback[1] ?? null)) {
                continue;
            }

            return (string) System::importStatic($callback[0])->generateAlias('', RecordDataContainer::create($table, 0, $record));
        }

        return null;
    }
}
