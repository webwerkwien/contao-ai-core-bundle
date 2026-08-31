<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FormModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a form and every field in it.
 *
 * `tl_form.ctable` is `tl_form_field`, so this takes the whole form definition
 * with it. The CLI wrapper says so in its prompt: a form is one row, a form
 * *definition* is usually a dozen.
 *
 * One `tl_undo` entry for the set, restorable through `contao:undo:restore`.
 */
#[AsCommand(name: 'contao:form:delete', description: 'Delete a form')]
class FormDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return FormModel::class; }
    protected function entityName(): string { return 'Form'; }
}
