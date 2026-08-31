<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ImageSizeItemModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:image-size-item:update', description: 'Update a media-query variant of an image size')]
class ImageSizeItemUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return ImageSizeItemModel::class; }
    protected function entityName(): string { return 'Image size item'; }
}
