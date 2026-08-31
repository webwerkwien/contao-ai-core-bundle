<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FormFieldModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:form-field:read', description: 'Read a form field record as JSON')]
class FormFieldReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return FormFieldModel::class; }
    protected function entityName(): string { return 'Form field'; }
}
