<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\FaqCategoryModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:faq-category:read', description: 'Read a FAQ category record as JSON')]
class FaqCategoryReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return FaqCategoryModel::class; }
    protected function entityName(): string { return 'FAQ category'; }
}
