<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ThemeModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a theme and everything under it.
 *
 * This is by far the widest cascade in the bundle. `tl_theme.ctable` declares
 * four child tables — `tl_module`, `tl_layout`, `tl_image_size`, `tl_content` —
 * and the collector recurses, so `tl_image_size_item` comes along underneath
 * the sizes. On the demo install that is one theme, 41 modules, 5 layouts and
 * 3 image sizes: a four-figure `rowsTotal` is entirely possible on a real site.
 *
 * Every row lands in a single `tl_undo` entry and stays restorable, but a
 * caller should see the size of what it is asking for. The CLI wrapper spells
 * the child tables out in its confirmation prompt for that reason.
 */
#[AsCommand(name: 'contao:theme:delete', description: 'Delete a theme with its modules, layouts and image sizes')]
class ThemeDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return ThemeModel::class; }
    protected function entityName(): string { return 'Theme'; }
}
