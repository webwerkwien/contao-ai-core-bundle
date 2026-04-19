<?php

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:file:process', description: 'Validate and optionally resize a file already on the server')]
class FileProcessCommand extends AbstractWriteCommand
{
    private const GD_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path',          null, InputOption::VALUE_REQUIRED, 'File path relative to Contao root, e.g. files/images/photo.jpg');
        $this->addOption('allowed-types', null, InputOption::VALUE_OPTIONAL, 'Comma-separated allowed extensions (overrides Contao config)', '');
        $this->addOption('max-width',     null, InputOption::VALUE_OPTIONAL, 'Max image width in pixels (0 = no limit)', '0');
        $this->addOption('max-height',    null, InputOption::VALUE_OPTIONAL, 'Max image height in pixels (0 = no limit)', '0');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path    = ltrim(str_replace('\', '/', $path), '/');
        $absPath = rtrim($this->projectDir, '/') . '/' . $path;

        if (!file_exists($absPath)) {
            return $this->outputError("File not found: {$path}");
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

        $allowedTypesOpt = $this->input->getOption('allowed-types');
        if ($allowedTypesOpt !== '') {
            $allowed = array_map('trim', explode(',', $allowedTypesOpt));
        } else {
            $this->framework->initialize();
            $contaoTypes = $GLOBALS['TL_CONFIG']['uploadTypes'] ?? 'jpg,jpeg,png,gif,pdf,svg';
            $allowed     = array_map('trim', explode(',', $contaoTypes));
        }

        if (!in_array($ext, $allowed, true)) {
            return $this->outputError("Extension '{$ext}' is not allowed. Allowed: " . implode(', ', $allowed));
        }

        $resized = false;
        if (in_array($ext, self::GD_IMAGE_TYPES, true)) {
            $maxW = (int) $this->input->getOption('max-width');
            $maxH = (int) $this->input->getOption('max-height');

            if ($maxW === 0 && $maxH === 0) {
                if (!isset($GLOBALS['TL_CONFIG'])) {
                    $this->framework->initialize();
                }
                $maxW = (int) ($GLOBALS['TL_CONFIG']['imageWidth']  ?? 0);
                $maxH = (int) ($GLOBALS['TL_CONFIG']['imageHeight'] ?? 0);
            }

            if ($maxW > 0 || $maxH > 0) {
                $resized = $this->resizeIfNeeded($absPath, $ext, $maxW, $maxH);
                if ($resized === null) {
                    return $this->outputError("Could not read image: {$path}");
                }
            }
        }

        $this->outputSuccess([
            'path'    => $path,
            'ext'     => $ext,
            'resized' => $resized,
            'bytes'   => filesize($absPath),
        ]);
        return Command::SUCCESS;
    }

    private function resizeIfNeeded(string $absPath, string $ext, int $maxW, int $maxH): ?bool
    {
        [$srcW, $srcH] = @getimagesize($absPath);
        if (!$srcW || !$srcH) {
            return null;
        }

        $needsResize = ($maxW > 0 && $srcW > $maxW) || ($maxH > 0 && $srcH > $maxH);
        if (!$needsResize) {
            return false;
        }

        $ratio = $srcW / $srcH;
        $newW  = $srcW;
        $newH  = $srcH;
        if ($maxW > 0 && $newW > $maxW) {
            $newW = $maxW;
            $newH = (int) round($newW / $ratio);
        }
        if ($maxH > 0 && $newH > $maxH) {
            $newH = $maxH;
            $newW = (int) round($newH * $ratio);
        }

        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($absPath),
            'png'         => @imagecreatefrompng($absPath),
            'gif'         => @imagecreatefromgif($absPath),
            'webp'        => @imagecreatefromwebp($absPath),
            default       => null,
        };
        if (!$src) {
            return null;
        }

        $dst = imagecreatetruecolor($newW, $newH);

        if (in_array($ext, ['png', 'gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        $saved = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($dst, $absPath, 85),
            'png'         => imagepng($dst, $absPath, 7),
            'gif'         => imagegif($dst, $absPath),
            'webp'        => imagewebp($dst, $absPath, 85),
            default       => false,
        };

        imagedestroy($src);
        imagedestroy($dst);

        return $saved;
    }
}
