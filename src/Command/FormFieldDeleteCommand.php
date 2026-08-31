<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FormFieldModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a single form field.
 *
 * No cascade — a field has no child table. The gap it leaves in the `sorting`
 * sequence is harmless: Contao sorts by the column, it does not require the
 * numbers to be contiguous.
 */
#[AsCommand(name: 'contao:form-field:delete', description: 'Delete a form field')]
class FormFieldDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return FormFieldModel::class; }
    protected function entityName(): string { return 'Form field'; }
}
