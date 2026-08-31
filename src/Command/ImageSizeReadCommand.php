<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ImageSizeModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `record:list tl_image_size` covers browsing; this covers one record in full.
 * The difference matters here: a size has some seventeen columns, and naming
 * them all in `--fields` to see one of them is worse than asking for the row.
 */
#[AsCommand(name: 'contao:image-size:read', description: 'Read an image size record as JSON')]
class ImageSizeReadCommand extends AbstractModelReadCommand
{
    protected function modelClass(): string { return ImageSizeModel::class; }
    protected function entityName(): string { return 'Image size'; }
}
