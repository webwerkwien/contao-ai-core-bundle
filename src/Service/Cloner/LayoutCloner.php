<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

/**
 * Clones a tl_layout record into the same theme, as Contao's copy does.
 *
 * The module assignment (`modules`) and the style sheets stay as they are, so the
 * clone renders the same modules until it is updated. The answer lists those fields
 * in `references`.
 */
class LayoutCloner extends AbstractDcaCloner
{
    protected function table(): string
    {
        return 'tl_layout';
    }

    protected function nameField(): string
    {
        return 'name';
    }
}
