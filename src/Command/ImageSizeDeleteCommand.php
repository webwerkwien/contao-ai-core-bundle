<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ImageSizeModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deleting a size takes its media-query variants with it.
 *
 * Nothing here arranges that: Contao's own DCA declares
 * `tl_image_size.ctable = ['tl_image_size_item']` and
 * `tl_image_size_item.ptable = 'tl_image_size'`, and RecordCascadeCollector has
 * followed `ctable` recursively since v0.2.8. The items are collected, deleted
 * before their parent, and filed in a single `tl_undo` row with the rest.
 *
 * Verified against the live DCA on 2026-08-31 rather than assumed — the same
 * assumption went unchecked on 2026-08-24 and left orphans behind.
 */
#[AsCommand(name: 'contao:image-size:delete', description: 'Delete an image size and its media-query variants')]
class ImageSizeDeleteCommand extends AbstractModelDeleteCommand
{
    protected function modelClass(): string { return ImageSizeModel::class; }
    protected function entityName(): string { return 'Image size'; }
}
