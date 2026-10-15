<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Theme;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\Theme\ThemeArchive;

/**
 * What ThemeArchive refuses before Contao's theme manager runs (v1.9.0). The export and
 * the import themselves are Contao's code; they ran on c5 against 5.3, 5.7 and 6.0
 * (round trip of the demo theme: 42 modules, every layout reference mapped).
 */
class ThemeArchiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/contao-ai-theme-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/system/tmp', 0777, true);
        touch($this->dir . '/system/tmp/site.sql');
        touch($this->dir . '/system/tmp/notes.txt');
        touch($this->dir . '/outside.cto');
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...(glob($this->dir . '/system/tmp/*') ?: []), $this->dir . '/outside.cto']);
        rmdir($this->dir . '/system/tmp');
        rmdir($this->dir . '/system');
        rmdir($this->dir);
    }

    private function archive(?Connection $connection = null): ThemeArchive
    {
        return new ThemeArchive(
            $this->createMock(ContaoFramework::class),
            $connection ?? $this->createMock(Connection::class),
            $this->createMock(DcaCallbacks::class),
            $this->dir,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'outside system/tmp'  => ['outside.cto', 'must lie in system/tmp/'];
        yield 'climbing out'        => ['system/tmp/../outside.cto', 'must lie in system/tmp/'];
        yield 'an example website'  => ['system/tmp/site.sql', 'example website'];
        yield 'no archive'          => ['system/tmp/notes.txt', 'Not a theme archive'];
        yield 'missing'             => ['system/tmp/gone.cto', 'No such file'];
    }

    /**
     * @dataProvider refused
     */
    public function testWhatIsNoThemeArchiveInSystemTmpIsRefused(string $path, string $message): void
    {
        foreach (['analyse', 'import'] as $method) {
            try {
                $this->archive()->{$method}($path);
                self::fail("$method accepted $path");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $method);
            }
        }
    }

    public function testAMissingThemeIsNotExported(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Theme 9 not found');

        $this->archive($connection)->export(9);
    }
}
