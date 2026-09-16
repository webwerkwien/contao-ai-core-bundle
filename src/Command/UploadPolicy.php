<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Config;
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
 * The rules are those of `Contao\FileUpload::uploadTo()` (5.7.13), in its order:
 * size, image dimensions, SVG sanitising, extension. The one deliberate difference:
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
     */
    protected function resizeAfterUpload(string $targetPath): bool
    {
        // Same early return as FileUpload::resizeUploadedImage(), before anything
        // of the back-end class is constructed on the console.
        if (!\in_array($this->extensionOf($targetPath), self::IMAGE_EXTENSIONS, true)
            || (Config::get('imageWidth') < 1 && Config::get('imageHeight') < 1)
        ) {
            return false;
        }

        $upload = new class() extends FileUpload {
            public function resize(string $path): bool
            {
                return (bool) $this->resizeUploadedImage($path);
            }
        };

        return $upload->resize($targetPath);
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
