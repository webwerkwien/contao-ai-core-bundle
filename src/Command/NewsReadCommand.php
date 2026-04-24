<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news:read', description: 'Read a Contao news entry as JSON')]
class NewsReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return NewsModel::class; }
    protected function entityName(): string { return 'News entry'; }

    protected function postProcessRow(array $row): array
    {
        if (isset($row['headline']) && is_string($row['headline'])) {
            $headline = StringUtil::deserialize($row['headline'], true);
            if (isset($headline['value'])) {
                $row['headline'] = $headline;
            }
        }
        return $row;
    }
}
