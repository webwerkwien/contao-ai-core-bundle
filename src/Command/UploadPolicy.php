<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Config;
use Contao\File;
use Contao\FileUpload;
use Contao\StringUtil;
use Contao\System;

/**
 * What goes into files/ is what the installation allows to be uploaded.
 *
 * Until v0.13.0 `contao:file:write` checked none of it — any extension, a hard-coded
 * 10 MB instead of `maxFileSize`, no image limits, no SVG sanitising — and
 * `contao:file:process --allowed-types` could widen the system list. See
 * UploadPolicyTest for the measurement.
 *
 * The rules are those of `Contao\FileUpload::uploadTo()` (5.7.13): size, image
 * dimensions, SVG sanitising, extension. Contao checks the extension last; here it
 * is checked together with the size, before the image is read — only the reported
 * reason differs when a file breaks more than one rule. The one deliberate difference:
 * Contao also caps the size at PHP's `upload_max_filesize`, which governs HTTP
 * uploads and does not apply to a file that arrived over SCP.
 */
trait UploadPolicy
{
    private const IMAGE_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'jxl'];

    /**
     * Refuse a file the installation would not accept as an upload.
     *
     * Needs the framework initialised (reads `Config`). May change the source
     * file: an SVG is sanitised in place, as Contao does with the temp file.
     *
     * @return string|null the reason, or null when the file may be written
     */
    protected function refuseDisallowedUpload(string $targetPath, string $sourceFile): ?string
    {
        $size = filesize($sourceFile);

        $violation = $this->uploadViolation(
            $targetPath,
            false === $size ? 0 : $size,
            (string) Config::get('uploadTypes'),
            (int) Config::get('maxFileSize'),
        );

        if (null !== $violation) {
            return $violation;
        }

        $ext = $this->extensionOf($targetPath);

        if (\in_array($ext, self::IMAGE_EXTENSIONS, true)
            && Config::get('imageWidth') && Config::get('imageHeight')
            && System::getContainer()->getParameter('contao.image.reject_large_uploads')
        ) {
            $dimensions = @getimagesize($sourceFile);

            if (false === $dimensions) {
                return "Not a readable image: {$targetPath}";
            }

            if ($dimensions[0] > Config::get('imageWidth') || $dimensions[1] > Config::get('imageHeight')) {
                return \sprintf(
                    'Image %s is %dx%d, larger than imageWidth x imageHeight (%dx%d). Nothing was written.',
                    $targetPath,
                    $dimensions[0],
                    $dimensions[1],
                    Config::get('imageWidth'),
                    Config::get('imageHeight'),
                );
            }
        }

        if (\in_array($ext, ['svg', 'svgz'], true) && !FileUpload::sanitizeSvg($sourceFile)) {
            return "Invalid SVG: {$targetPath}. Contao's upload would refuse it as well. Nothing was written.";
        }

        return null;
    }

    /**
     * Scale an image down to `imageWidth` × `imageHeight` after it was written, when
     * the installation resizes rather than refuses — as `FileUpload` does after the
     * move. Returns whether it was resized.
     *
     * The size is computed here and handed to Contao's `File::resizeTo()`, the call
     * `FileUpload::resizeUploadedImage()` ends in. Until v0.16.0 that method was called
     * itself, which had two flaws on the console (review and measurement 2026-09-16):
     *
     * - it calls `Message::addInfo()`, which needs a session. It only got through
     *   because the language file is not loaded there, so the message was empty and
     *   `Message::add()` returned early — with two PHP warnings in the log;
     * - with only one limit set, it scales to 0×0 and leaves an empty file (see
     *   resizeDimensions()). The back end does the same; the bundle no longer does.
     */
    protected function resizeAfterUpload(string $targetPath): bool
    {
        $maxWidth  = (int) Config::get('imageWidth');
        $maxHeight = (int) Config::get('imageHeight');

        if (!\in_array($this->extensionOf($targetPath), self::IMAGE_EXTENSIONS, true) || ($maxWidth < 1 && $maxHeight < 1)) {
            return false;
        }

        $file = new File($targetPath);

        // Same guards as Contao: not a GD image, or no readable size → leave it.
        if (!$file->isGdImage) {
            return false;
        }

        $size = $file->imageSize;

        if (!isset($size[0], $size[1])) {
            return false;
        }

        $target = $this->resizeDimensions((int) $size[0], (int) $size[1], $maxWidth, $maxHeight);

        if (null === $target) {
            return false;
        }

        $file->resizeTo($target[0], $target[1]);

        System::getContainer()->get('monolog.logger.contao.files')->info('File "' . $targetPath . '" was scaled down to the maximum dimensions');

        return true;
    }

    /**
     * The size an image is scaled down to, or null when it fits.
     *
     * Contao's order: the width limit first, then the height limit on the result,
     * each keeping the aspect ratio. Unlike `FileUpload::resizeUploadedImage()`
     * (5.3 to 6.0), a limit below 1 counts as unset. There, `imageWidth=100` with
     * `imageHeight=0` passes the width step and then finds any height `> 0`, so it
     * scales to `round(0 * w / h)` × 0 — measured on c5: a 600×20 PNG became a file
     * of 0 bytes.
     *
     * @return array{int, int}|null
     */
    protected function resizeDimensions(int $width, int $height, int $maxWidth, int $maxHeight): ?array
    {
        $resize = false;

        if ($maxWidth > 0 && $width > $maxWidth) {
            $height = max(1, (int) round($maxWidth * $height / $width));
            $width  = $maxWidth;
            $resize = true;
        }

        if ($maxHeight > 0 && $height > $maxHeight) {
            $width  = max(1, (int) round($maxHeight * $width / $height));
            $height = $maxHeight;
            $resize = true;
        }

        return $resize ? [$width, $height] : null;
    }

    /**
     * The pure half of the rules: extension and size.
     *
     * @return string|null the reason, or null when both hold
     */
    protected function uploadViolation(string $targetPath, int $size, string $uploadTypes, int $maxFileSize): ?string
    {
        if ($size > $maxFileSize) {
            return \sprintf(
                'File %s has %d bytes, more than maxFileSize (%d). Nothing was written.',
                $targetPath,
                $size,
                $maxFileSize,
            );
        }

        $ext     = $this->extensionOf($targetPath);
        $allowed = StringUtil::trimsplit(',', strtolower($uploadTypes));

        if ('' === $ext || !\in_array($ext, $allowed, true)) {
            return \sprintf(
                "Extension '%s' is not in uploadTypes, so the installation does not accept it. "
                . 'Nothing was written. Allowed: %s',
                $ext,
                implode(', ', $allowed),
            );
        }

        return null;
    }

    /**
     * The types `--allowed-types` leaves: a subset of the system list, never more.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when the option names a type the system does not allow
     */
    protected function narrowAllowedTypes(string $option, string $uploadTypes): array
    {
        $notEmpty = static fn (string $type): bool => '' !== $type;
        $system   = array_values(array_filter(StringUtil::trimsplit(',', strtolower($uploadTypes)), $notEmpty));

        if ('' === trim($option)) {
            return $system;
        }

        $requested = array_values(array_filter(StringUtil::trimsplit(',', strtolower($option)), $notEmpty));
        $widening  = array_values(array_diff($requested, $system));

        if ([] !== $widening) {
            throw new \InvalidArgumentException(\sprintf(
                '--allowed-types may only narrow uploadTypes, not widen it. Not allowed by the installation: %s',
                implode(', ', $widening),
            ));
        }

        return $requested;
    }

    private function extensionOf(string $path): string
    {
        $base = basename($path);
        $dot  = strrpos($base, '.');

        // ".htaccess" has the extension "htaccess", as Symfony's Path does.
        return false === $dot ? '' : strtolower(substr($base, $dot + 1));
    }
}
