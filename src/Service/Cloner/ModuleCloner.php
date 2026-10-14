<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

/**
 * Clones a tl_module record into the same theme, as Contao's copy does.
 *
 * Nothing is reset (no `doNotCopy` field in Contao 5.3 to 6.0) and every reference
 * stays: the clone shows the same pages, archives and forms until it is updated. The
 * answer lists those fields in `references`.
 */
class ModuleCloner extends AbstractDcaCloner
{
    protected function table(): string
    {
        return 'tl_module';
    }

    protected function nameField(): string
    {
        return 'name';
    }
}
