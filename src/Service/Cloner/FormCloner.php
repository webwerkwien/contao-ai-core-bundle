<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

/**
 * Clones a tl_form record with all its tl_form_field children.
 *
 * As Contao's copy: `alias` and `formID` (both `doNotCopy`) are not taken along. The
 * alias is generated anew from the title, as Contao's `save_callback` would on the
 * first save; `formID` stays empty, because two forms with the same ID each process
 * the other's submissions. The fields keep everything, including their order.
 */
class FormCloner extends AbstractDcaCloner
{
    protected function table(): string
    {
        return 'tl_form';
    }

    protected function nameField(): string
    {
        return 'title';
    }

    protected function childTables(array $options): array
    {
        return ['tl_form_field'];
    }

    protected function derivedValues(string $table, array $row): array
    {
        return 'tl_form' === $table ? ['alias' => $this->uniqueAlias('tl_form', (string) ($row['title'] ?? ''))] : [];
    }
}
