<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

/**
 * Reading `eval`-less option lists out of a DCA field definition.
 *
 * Extracted from `DcaSchemaCommand` on 2026-09-11, when the write path needed
 * the same answer. `AbstractReadCommand` and `AbstractWriteCommand` share no
 * base class, so a copy was the alternative — and a second reading of the same
 * DCA construct is exactly the duplication this project keeps removing rather
 * than adding. The read side answers *which values exist*, the write side
 * *whether this value is one of them*; they must not be able to disagree.
 *
 * ⚠️ **This covers `options` only.** A field whose values come from an
 * `options_callback` or a `foreignKey` has options too, but not here — see
 * `DcaSchemaCommand::optionsSource()` for how those are reported, and
 * `AbstractWriteCommand::refuseInvalidOptions()` for why only this one is
 * enforced.
 */
trait ReadsDcaOptions
{
    /**
     * The values a caller may actually set, in Contao's two-and-a-half forms.
     *
     * 🔴 Until 2026-08-31 this was `array_keys($def['options'])`
     * unconditionally, which is right for exactly one of them:
     *
     *   array('de' => 'Deutsch')                   assoc — the key IS the value
     *   array('map_default', 'map_always')         list  — the key is 0, 1, …
     *
     * Contao's own DCAs use the list form almost everywhere, so almost every
     * answer was wrong: `tl_page.sitemap` came back as `[0, 1, 2]` where the
     * DCA declares `map_default, map_always, map_never`.
     *
     * 🎯 **It never showed up because it reads correctly.** Looking at the line
     * you picture the associative form, and there it is right. And the wrong
     * answer looks like an answer — `tl_content` declares `array(1, 2, …, 12)`,
     * whose keys are `0..11`, so the reply is plausible and off by one
     * throughout. A caller building `--set` from it gets rejected by the DCA
     * and goes looking in the wrong place.
     *
     * Reported by the parallel session working on the wienerwandern booking
     * module, which hit it on a table of its own and checked `tl_page` to rule
     * out its own DCA.
     *
     * Nested option groups are flattened: Contao allows
     * `array('Group' => array('a', 'b'))` for an optgroup, and the group name
     * is not something anyone can set either.
     *
     * @param array<string, mixed> $def
     *
     * @return list<string>|null
     */
    protected function optionValues(array $def): ?array
    {
        if (!isset($def['options']) || !\is_array($def['options'])) {
            return null;
        }

        return $this->flattenOptions($def['options']);
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return list<string>
     */
    private function flattenOptions(array $options): array
    {
        $values = [];

        foreach ($options as $key => $value) {
            if (\is_array($value)) {
                // An optgroup: the group name is a label, its members are values.
                $values = [...$values, ...$this->flattenOptions($value)];
                continue;
            }

            // List form: the value is the value. Associative: the key is.
            $values[] = \is_int($key) ? (string) $value : (string) $key;
        }

        return array_values(array_unique($values));
    }
}
