<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\Widget;

/**
 * The `tl_page` fields a cloned page does not take from its source.
 *
 * Contao's back-end copy resets every field with `doNotCopy` to its empty value
 * (`DC_Table::copy()`, `Widget::getEmptyValueByFieldType()`). Of the seven on
 * `tl_page`, the cloner handles three elsewhere — `alias` is regenerated from the
 * title, `language` and `urlPrefix` are kept, because a clone into another language
 * sets both in the same call and a root on the same domain and prefix is refused.
 * The other four are reset here:
 *
 * | Field | Copied until v1.7.3, with this effect |
 * |---|---|
 * | `published` | every cloned page was as public as its source, while both guides said "unpublished" |
 * | `fallback` | a cloned root became a second main language on the same domain |
 * | `disableLanguageRedirect` | the clone took the language redirect setting of the source root |
 * | `robotsTxt` | the clone served the robots.txt of the source root |
 *
 * Found on showcase.conpai.eu on 2026-10-14: a recursive clone of the English tree came
 * out published. Which language is the main one is the operator's decision, so a clone
 * becomes it only with `fallback` in `--modifications` — those are applied after this.
 */
final class PageCopyReset
{
    public const FIELDS = ['published', 'fallback', 'disableLanguageRedirect', 'robotsTxt'];

    /**
     * @param array<string, mixed> $row    the source row as stored
     * @param array<string, mixed> $fields `$GLOBALS['TL_DCA']['tl_page']['fields']`
     *
     * @return array<string, mixed> the empty value per field the installation has
     */
    public static function values(array $row, array $fields): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            // An older Contao may lack a column; writing it would fail the save. Without
            // its DCA definition the empty value is unknown, and a guessed '' fails a
            // boolean column in strict SQL mode.
            if (!\array_key_exists($field, $row) || !isset($fields[$field]['sql'])) {
                continue;
            }

            $values[$field] = Widget::getEmptyValueByFieldType($fields[$field]['sql']);
        }

        return $values;
    }
}
