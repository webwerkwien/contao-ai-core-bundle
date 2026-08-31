<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ImageSizeItemModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:image-size-item:read', description: 'Read a media-query variant as JSON')]
class ImageSizeItemReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return ImageSizeItemModel::class; }
    protected function entityName(): string { return 'Image size item'; }
}
