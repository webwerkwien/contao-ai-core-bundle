<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\UploadPolicy;

/**
 * What goes into files/ is what the installation allows to be uploaded — no more,
 * and not less.
 *
 * 🔴 **Measured on 2026-09-16 on c5 (Contao 5.7.13, core-bundle v0.12.0)**, raised by
 * Michael in the ConpAI 1.0 acceptance test ("shouldn't every upload the system
 * defines be possible?"):
 *
 * | Contao setting | on c5 | `contao:file:write` |
 * |---|---|---|
 * | `uploadTypes` | 60+ extensions | **not checked** — any extension was written |
 * | `maxFileSize` | 20 MB | **a hard-coded 10 MB** instead |
 * | `imageWidth` / `imageHeight` | 0 | not applied |
 * | SVG sanitising | on every back-end upload | **not done** |
 *
 * `contao:file:process` did check type and size — as a separate step, and its
 * `--allowed-types` replaced the system list, so it could also **widen** it.
 *
 * ## The rules, taken from `Contao\FileUpload::uploadTo()` (5.7.13)
 *
 * - size above `maxFileSize` → refused
 * - an image above `imageWidth` × `imageHeight` → refused when
 *   `contao.image.reject_large_uploads` is set, otherwise resized after the move
 * - `svg`/`svgz` → `FileUpload::sanitizeSvg()`, refused if it fails
 * - extension not in `uploadTypes` (lower-cased) → refused
 *
 * The pure decisions are pinned here; the calls into Contao are verified live.
 */
class UploadPolicyTest extends TestCase
{
    private const SYSTEM = 'jpg,jpeg,gif,png,svg,webp,pdf,woff2,css';

    private function subject(): object
    {
        return new class {
            use UploadPolicy {
                uploadViolation as public;
                narrowAllowedTypes as public;
            }
        };
    }

    // --- type ---

    public function testAnExtensionTheSystemAllowsPasses(): void
    {
        $this->assertNull($this->subject()->uploadViolation('files/conpai/bot.png', 1000, self::SYSTEM, 20480000));
    }

    public function testTheExtensionIsComparedCaseInsensitively(): void
    {
        $this->assertNull($this->subject()->uploadViolation('files/conpai/LOGO.SVG', 1000, 'JPG,SVG', 20480000));
    }

    /**
     * @dataProvider notAllowed
     */
    public function testAnExtensionTheSystemDoesNotAllowIsRefused(string $path, string $ext): void
    {
        $violation = $this->subject()->uploadViolation($path, 10, self::SYSTEM, 20480000);

        $this->assertNotNull($violation);
        $this->assertStringContainsString($ext, $violation);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notAllowed(): array
    {
        return [
            'php'           => ['files/x/shell.php', 'php'],
            'htaccess'      => ['files/x/.htaccess', 'htaccess'],
            'no extension'  => ['files/x/README', "''"],
            'double ending' => ['files/x/bild.png.phtml', 'phtml'],
        ];
    }

    // --- size ---

    public function testTheSystemMaximumReplacesTheOldTenMegabytes(): void
    {
        // 15 MB: refused until v0.12.0 by the hard-coded limit, allowed by c5's 20 MB.
        $this->assertNull($this->subject()->uploadViolation('files/x/video.pdf', 15 * 1024 * 1024, self::SYSTEM, 20480000));
    }

    public function testAFileAboveMaxFileSizeIsRefused(): void
    {
        $violation = $this->subject()->uploadViolation('files/x/big.pdf', 20480001, self::SYSTEM, 20480000);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('maxFileSize', $violation);
    }

    // --- file process --allowed-types ---

    public function testAllowedTypesMayNarrowTheSystemList(): void
    {
        $this->assertSame(['jpg', 'png'], $this->subject()->narrowAllowedTypes('JPG, png', self::SYSTEM));
    }

    public function testAllowedTypesMayNotWidenTheSystemList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('php');

        $this->subject()->narrowAllowedTypes('jpg,php', self::SYSTEM);
    }

    public function testWithoutAllowedTypesTheSystemListApplies(): void
    {
        $this->assertSame(explode(',', self::SYSTEM), $this->subject()->narrowAllowedTypes('', self::SYSTEM));
    }

    // --- wiring ---

    public function testFileWriteEnforcesThePolicyAndDropsTheOldLimit(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/FileWriteCommand.php');

        $this->assertStringContainsString('use UploadPolicy;', $source);
        $this->assertStringContainsString('refuseDisallowedUpload(', $source);
        $this->assertStringNotContainsString('10 * 1024 * 1024', $source);
        $this->assertStringNotContainsString('maximum allowed size of 10 MB', $source);
    }

    public function testThePolicySanitisesSvgsAndHandlesLargeImagesLikeTheBackEnd(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/UploadPolicy.php');

        foreach (['sanitizeSvg(', 'reject_large_uploads', 'resizeTo(', 'uploadTypes', 'maxFileSize'] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        // Not Contao's resizeUploadedImage(): it calls Message::addInfo(), which needs
        // a session the console does not have — and it scales to 0×0 when only one
        // limit is set. See the resize tests below.
        $this->assertStringNotContainsString('->resizeUploadedImage(', $source);
    }

    // --- resize ---

    /**
     * 🔴 Measured on c5 (5.7.13) on 2026-09-16: `imageWidth=100`, `imageHeight=0`,
     * a 600×20 PNG through `file upload` → **0 bytes on disk**, `resized: true`.
     * `FileUpload::resizeUploadedImage()` checks the width (→ 100×3), then
     * `3 > imageHeight (0)` → width `round(0 * 100 / 3)` = 0, height 0. The back end
     * computes the same; the bundle no longer does.
     *
     * @return iterable<string, array{int, int, int, int, array{int, int}|null}>
     */
    public static function resizeCases(): iterable
    {
        yield 'within both limits' => [80, 40, 100, 100, null];
        yield 'too wide' => [600, 20, 100, 100, [100, 3]];
        yield 'too high' => [20, 600, 100, 100, [3, 100]];
        yield 'both, width decides' => [1000, 500, 100, 100, [100, 50]];
        yield 'both, height decides after width' => [400, 1000, 200, 100, [40, 100]];
        yield 'only a width limit (the 0×0 case)' => [600, 20, 100, 0, [100, 3]];
        yield 'only a height limit' => [20, 600, 0, 100, [3, 100]];
        yield 'only a width limit, image within' => [80, 2000, 100, 0, null];
        yield 'never below one pixel' => [5000, 1, 100, 100, [100, 1]];
    }

    /**
     * @param array{int, int}|null $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resizeCases')]
    public function testResizeDimensions(int $width, int $height, int $maxWidth, int $maxHeight, ?array $expected): void
    {
        $subject = new class {
            use UploadPolicy {
                resizeDimensions as public;
            }
        };

        $this->assertSame($expected, $subject->resizeDimensions($width, $height, $maxWidth, $maxHeight));
    }

    public function testFileProcessCannotWidenTheSystemList(): void
    {
        $this->assertStringContainsString(
            'narrowAllowedTypes(',
            (string) file_get_contents(__DIR__ . '/../../src/Command/FileProcessCommand.php'),
        );
    }
}
