<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\LayoutModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `tl_layout` declares no `ctable`, so nothing hangs below a layout. Pages that
 * reference it are a different matter: `tl_page.layout` points here, and those
 * pages are not touched — they fall back to their parent's layout, which is
 * Contao's own behaviour for a missing layout, not something arranged here.
 */
#[AsCommand(name: 'contao:layout:delete', description: 'Delete a page layout')]
class LayoutDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return LayoutModel::class; }
    protected function entityName(): string { return 'Layout'; }
}
