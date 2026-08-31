<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FormModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:form:read', description: 'Read a form record as JSON')]
class FormReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return FormModel::class; }
    protected function entityName(): string { return 'Form'; }
}
