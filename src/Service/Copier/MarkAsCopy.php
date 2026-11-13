<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Copier;

use Contao\StringUtil;

/**
 * The mark a copied record carries in the field its DCA names in `config.markAsCopy`,
 * as `DC_Table::copy()` sets it (5.3 to 6.0): the label `MSC.copyOf` of the loaded
 * language ("%s (Kopie)", "%s (copy)") around the value; for an `inputUnit` field
 * around its `value`, the unit kept. An empty value stays empty, and a value that
 * already carries the mark is not marked again (contao/contao#6058).
 *
 * Only the copied record itself is marked, never its children.
 */
final class MarkAsCopy
{
    /**
     * @param string $label     `$GLOBALS['TL_LANG']['MSC']['copyOf']`
     * @param bool   $inputUnit whether the field's `inputType` is `inputUnit` — Contao
     *                          goes by the DCA, not by what the stored value looks like
     */
    public static function apply(string $label, mixed $stored, bool $inputUnit): mixed
    {
        if (!$inputUnit) {
            return \is_string($stored) && '' !== $stored ? self::mark($label, $stored) : $stored;
        }

        $value = StringUtil::deserialize($stored);

        if (!\is_array($value) || empty($value['value'])) {
            return $stored;
        }

        $value['value'] = self::mark($label, (string) $value['value']);

        return serialize($value);
    }

    /**
     * `DataContainer::markAsCopy()`. Contao 5.3 and 5.7 decode entities before they
     * look for the mark, 6.0 does not; decoding finds the mark in both cases.
     */
    private static function mark(string $label, string $value): string
    {
        if (preg_match('/' . preg_quote(\sprintf($label, ''), '/') . '/', StringUtil::decodeEntities($value))) {
            return $value;
        }

        return \sprintf($label, $value);
    }
}
