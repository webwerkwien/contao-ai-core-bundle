<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ImageSizeModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The field that actually decides which variant a browser loads is `sizes`,
 * not `width` — see the note on modern image handling. It is a plain column
 * here, so `--set sizes="(max-width: 1100px) 100vw, 1000px"` is all it takes;
 * the same goes for `densities`.
 */
#[AsCommand(name: 'contao:image-size:update', description: 'Update an image size')]
class ImageSizeUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return ImageSizeModel::class; }
    protected function entityName(): string { return 'Image size'; }
}
