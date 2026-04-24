<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\ContentModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:content:read', description: 'Read a Contao content element as JSON')]
class ContentReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return ContentModel::class; }
    protected function entityName(): string { return 'Content element'; }

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
