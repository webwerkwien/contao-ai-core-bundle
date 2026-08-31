<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ImageSizeItemModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deleting one variant, as opposed to deleting the size it belongs to.
 * `tl_image_size_item` declares no `ctable`, so there is nothing below it.
 */
#[AsCommand(name: 'contao:image-size-item:delete', description: 'Delete a single media-query variant')]
class ImageSizeItemDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return ImageSizeItemModel::class; }
    protected function entityName(): string { return 'Image size item'; }
}
