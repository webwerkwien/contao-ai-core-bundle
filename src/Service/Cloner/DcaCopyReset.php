<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\Widget;

/**
 * The values a copied row does not take from its source, read from the DCA.
 *
 * The same rules as `DC_Table::copyChildren()` in Contao 5.3 to 6.0, per field:
 *
 * | DCA | Contao copies |
 * |---|---|
 * | `inputType => 'password'` | the empty value — "never copy passwords" |
 * | `eval.unique` | the empty value |
 * | `eval.doNotCopy` or `eval.fallback` | the field's `default`, or the empty value |
 *
 * Read from the installation's DCA rather than listed per table, so a field a later
 * Contao marks `doNotCopy` is reset without a release of this bundle. `PageCopyReset`
 * predates this and stays: the page cloner deliberately keeps `language` and
 * `urlPrefix`, which this rule would empty.
 *
 * Measured for the tables cloned through this class (Contao 5.3.51):
 *
 * | Table | reset |
 * |---|---|
 * | `tl_form` | `alias`, `formID` |
 * | `tl_newsletter` | `alias`, `sent` |
 * | `tl_newsletter_recipients` | `addedOn` |
 * | `tl_form_field`, `tl_newsletter_channel`, `tl_module`, `tl_layout` | nothing |
 */
final class DcaCopyReset
{
    /**
     * @param array<string, mixed> $row    the source row as stored
     * @param array<string, mixed> $fields `$GLOBALS['TL_DCA'][$table]['fields']`
     *
     * @return array<string, mixed> the value to write instead, per field of the row
     */
    public static function values(array $row, array $fields): array
    {
        $values = [];

        foreach ($row as $field => $_) {
            $definition = $fields[$field] ?? null;

            // Without an `sql` definition the empty value is unknown, and a guessed ''
            // fails a boolean column in strict SQL mode. The primary key is never copied.
            if ('id' === $field || !\is_array($definition) || !isset($definition['sql'])) {
                continue;
            }

            $eval  = \is_array($definition['eval'] ?? null) ? $definition['eval'] : [];
            $empty = Widget::getEmptyValueByFieldType($definition['sql']);

            if ('password' === ($definition['inputType'] ?? null) || ($eval['unique'] ?? false)) {
                $values[$field] = $empty;
                continue;
            }

            if (!($eval['doNotCopy'] ?? false) && !($eval['fallback'] ?? false)) {
                continue;
            }

            // A closure default needs the DataContainer of a back-end request; none here.
            if (\array_key_exists('default', $definition) && !$definition['default'] instanceof \Closure) {
                $default        = $definition['default'];
                $values[$field] = \is_array($default) ? serialize($default) : $default;
                continue;
            }

            $values[$field] = $empty;
        }

        return $values;
    }
}
