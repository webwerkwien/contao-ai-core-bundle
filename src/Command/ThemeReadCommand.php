<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ThemeModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:theme:read', description: 'Read a theme record as JSON')]
class ThemeReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return ThemeModel::class; }
    protected function entityName(): string { return 'Theme'; }
}
