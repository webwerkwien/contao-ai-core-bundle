<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

/**
 * Which fields Contao stores as a serialized array.
 *
 * One definition for the three places that need it: the write check
 * (refuseUnstructuredValues), writing JSON (convertJsonStructuredFields) and reading
 * (convertStructuredFieldsForRead). See StructuredValueRefusalTest and
 * StructuredFieldRoundTripTest.
 */
final class StructuredFields
{
    /**
     * Input types whose widget stores a serialized array, independent of
     * `eval.multiple`. Counted in the DCA files of a stock 5.7.13 with all optional
     * bundles; `inputUnit`, `cud` and `chmod` have their own conversion on write.
     */
    public const INPUT_TYPES = [
        'moduleWizard', 'sectionWizard', 'rowWizard', 'tableWizard', 'optionWizard',
        'metaWizard', 'listWizard', 'keyValueWizard', 'imageSize', 'timePeriod',
        'rootPageDependentSelect', 'inputUnit', 'cud', 'chmod',
    ];

    /**
     * Whether a field's stored value is a serialized array: a structured input type,
     * or `eval.multiple` without `eval.csv` (which stores a comma-separated string).
     * `fileTree` is excluded — its values are binary UUIDs with their own conversion.
     *
     * @param array<string, mixed> $def
     */
    public static function storesArray(array $def): bool
    {
        $type = $def['inputType'] ?? null;

        if ('fileTree' === $type) {
            return false;
        }

        if (\in_array($type, self::INPUT_TYPES, true)) {
            return true;
        }

        return (bool) ($def['eval']['multiple'] ?? false) && !isset($def['eval']['csv']);
    }
}
