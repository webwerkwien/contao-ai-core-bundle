<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ModuleModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `tl_module` declares no `ctable`, so nothing hangs below a module. Layouts
 * referencing it in their `modules` wizard column are not touched — that column
 * holds a serialized structure, not a foreign key, and Contao skips a module it
 * cannot find rather than failing.
 */
#[AsCommand(name: 'contao:module:delete', description: 'Delete a front end module')]
class ModuleDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return ModuleModel::class; }
    protected function entityName(): string { return 'Module'; }
}
