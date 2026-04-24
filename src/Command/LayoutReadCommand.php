<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\LayoutModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:layout:read', description: 'Read a Contao layout record as JSON')]
class LayoutReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return LayoutModel::class; }
    protected function entityName(): string { return 'Layout'; }
}
