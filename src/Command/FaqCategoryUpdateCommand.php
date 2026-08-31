<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FaqCategoryModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:faq-category:update', description: 'Update a FAQ category')]
class FaqCategoryUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return FaqCategoryModel::class; }
    protected function entityName(): string { return 'FAQ category'; }
}
