<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\PathJail;

/**
 * 🔴 This command overwrites file contents without saving the
 * previous bytes — and declared that nowhere.
 *
 * ⚠️ Checked how Contao does it: `DC_Folder::source()` versions around the
 * write (`Versions::initialize()` … `create()`), but
 * `Versions::create()` is a `SELECT * FROM <table> WHERE id=?` — it saves
 * the **database row**, not the file content. Contao's own file editor
 * overwrites just as irretrievably; the stored `hash` lets you
 * detect a change, not undo it.
 *
 * 🎯 Hence no backup regime of our own that would deviate from Contao and raise
 * questions of storage location and retention — but the declaration. `irreversible`
 * exists in `AiContract` for exactly this, and a calling agent reads it
 * before it acts. **An irreversible effect that nobody announces is
 * the actual defect.**
 */
#[AiContract(
    writes: true,
    tables: ['tl_files'],
    trace: ['tl_log'],
    traceWhen: 'on-success',
    irreversible: 'overwrites the image file on disk — the original bytes are not kept anywhere',
    repeatable: false,
    answerShape: ['status', 'path'],
)]
#[AsCommand(name: 'contao:file:process', description: 'Validate and optionally resize a file already on the server')]
class FileProcessCommand extends AbstractWriteCommand
{
    use UploadPolicy;

    private const GD_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    // File operations belong under the FILES action, the same bucket the back
    // end's own file manager writes to.
    protected function systemLogAction(): string
    {
        return ContaoContext::FILES;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path',          null, InputOption::VALUE_REQUIRED, 'File path relative to Contao root, e.g. files/images/photo.jpg');
        $this->addOption('allowed-types', null, InputOption::VALUE_OPTIONAL, 'Comma-separated allowed extensions (overrides Contao config)', '');
        $this->addOption('max-width',     null, InputOption::VALUE_OPTIONAL, 'Max image width in pixels (0 = no limit)', '0');
        $this->addOption('max-height',    null, InputOption::VALUE_OPTIONAL, 'Max image height in pixels (0 = no limit)', '0');
        $this->addOption('max-file-size', null, InputOption::VALUE_OPTIONAL, 'Max allowed file size in bytes (0 = use Contao config)', '0');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        // Only files/, as every other file command (v1.14.0): the resize rewrote any
        // image in the project, public/ and vendor/ included.
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must start with files/ and must not contain ".."');
        }
        $absPath = rtrim($this->projectDir, '/') . '/' . $path;

        $real = realpath($absPath);
        if ($real === false || !PathJail::contains($this->projectDir, 'files', $absPath)) {
            return $this->outputError('Access denied: path is outside files/');
        }
        $absPath = $real;

        if (!file_exists($absPath)) {
            return $this->outputError("File not found: {$path}");
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

        // --allowed-types narrows the installation's uploadTypes, it never widens
        // it. Until v0.13.0 it replaced the list, so `--allowed-types php` passed.
        $this->framework->initialize();
        try {
            $allowed = $this->narrowAllowedTypes(
                (string) $this->input->getOption('allowed-types'),
                (string) ($GLOBALS['TL_CONFIG']['uploadTypes'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        if (!in_array($ext, $allowed, true)) {
            return $this->outputError("Extension '{$ext}' is not allowed. Allowed: " . implode(', ', $allowed));
        }

        // Check max file size
        $maxFileSizeOpt = (int) $this->input->getOption('max-file-size');
        if ($maxFileSizeOpt > 0) {
            $maxBytes = $maxFileSizeOpt;
        } else {
            if (!isset($GLOBALS['TL_CONFIG'])) {
                $this->framework->initialize();
            }
            $maxBytes = (int) ($GLOBALS['TL_CONFIG']['maxFileSize'] ?? 0);
        }
        if ($maxBytes > 0) {
            clearstatcache(true, $absPath);
            $fileBytes = filesize($absPath);
            if ($fileBytes > $maxBytes) {
                return $this->outputError(sprintf(
                    'File size %d bytes exceeds the maximum allowed size of %d bytes',
                    $fileBytes,
                    $maxBytes
                ));
            }
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

        clearstatcache(true, $absPath);
        // 🔴 This command used to leave NOTHING behind — no
        // tl_version, no system log. An irreversible overwrite without
        // any entry, and the contract reader reported it itself when the
        // declaration was written: *"writes: true but no trace declared —
        // a caller cannot tell whether that means 'leaves nothing behind' or
        // 'not stated'."* It was the former, and that was the defect.
        //
        // The bytes cannot be brought back (Contao does not either,
        // see the class comment). But who changed the file when
        // belongs on record — otherwise the operation is not only irreversible,
        // but invisible too.
        $payload = [
            'path'    => $path,
            'ext'     => $ext,
            'resized' => $resized,
            'bytes'   => filesize($absPath),
        ];

        $this->systemLog?->write(
            sprintf('%s %s', $this->getName(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            (string) $this->getName(),
            (string) $this->resolveOperator(),
            ContaoContext::FILES,
        );

        $this->outputSuccess($payload);
        return Command::SUCCESS;
    }

    private function resizeIfNeeded(string $absPath, string $ext, int $maxW, int $maxH): ?bool
    {
        // Guard against corrupt/invalid images: getimagesize() returns false,
        // and destructuring false ([$a, $b] = false) would emit "Trying to
        // access array offset on bool" warnings that could corrupt the JSON
        // output. Check the result before destructuring (cf. Contao #9975).
        $info = @getimagesize($absPath);
        if ($info === false) {
            return null;
        }
        [$srcW, $srcH] = $info;
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

        if (in_array($ext, ['png', 'gif', 'webp'], true)) {
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

        // No imagedestroy(): GD resources became GdImage objects in PHP 8.0 and
        // are released by the garbage collector, so the call has had no effect
        // since then — and PHP 8.5 deprecates it. Dropping it keeps the bundle
        // notice-free across the whole ^8.2 range.

        return $saved;
    }
}
