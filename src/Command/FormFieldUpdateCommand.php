<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FormFieldModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:form-field:update', description: 'Update a form field')]
class FormFieldUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return FormFieldModel::class; }
    protected function entityName(): string { return 'Form field'; }
}
