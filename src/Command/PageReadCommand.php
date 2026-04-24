<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:page:read', description: 'Read a Contao page record as JSON')]
class PageReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return PageModel::class; }
    protected function entityName(): string { return 'Page'; }

    protected function postProcessRow(array $row): array
    {
        $layoutId = (int) $row['layout'];
        if ($layoutId === 0) {
            $parent = PageModel::findById((int) $row['pid']);
            while ($parent !== null && (int) $parent->layout === 0) {
                $parent = PageModel::findById((int) $parent->pid);
            }
            $layoutId = $parent !== null ? (int) $parent->layout : 0;
        }
        $row['layout_effective'] = $layoutId;
        return $row;
    }
}
