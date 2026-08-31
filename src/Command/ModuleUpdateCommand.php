<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ModuleModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Changing `type` on an existing module is allowed here, as it is in the back
 * end: the palette changes, previously irrelevant columns start applying, and
 * the record keeps whatever it had in them. Contao behaves the same way and
 * shows the new palette; no attempt is made to be stricter than the back end.
 */
#[AsCommand(name: 'contao:module:update', description: 'Update a front end module')]
class ModuleUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return ModuleModel::class; }
    protected function entityName(): string { return 'Module'; }
}
