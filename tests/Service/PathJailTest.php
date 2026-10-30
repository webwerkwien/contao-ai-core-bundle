<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Webwerkwien\ContaoAiCoreBundle\Service\PathJail;

/**
 * The file and template commands stay in files/ and templates/ once links are resolved
 * (v1.14.0); the project directory is no longer the boundary.
 */
class PathJailTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/contao-ai-jail-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/project/files/media', 0777, true);
        mkdir($this->dir . '/project/config', 0777, true);
        mkdir($this->dir . '/shared/files', 0777, true);
        file_put_contents($this->dir . '/project/.env.local', 'APP_SECRET=x');
        file_put_contents($this->dir . '/project/files/media/a.txt', 'a');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function link(string $target, string $link): void
    {
        if (!@symlink($target, $link)) {
            self::markTestSkipped('This system cannot create symbolic links.');
        }
    }

    public function testAFileInsideTheRootPasses(): void
    {
        self::assertTrue(PathJail::contains($this->dir . '/project', 'files', $this->dir . '/project/files/media/a.txt'));
        self::assertTrue(PathJail::contains($this->dir . '/project', 'files', $this->dir . '/project/files/new/b.txt'), 'a path still to be created');
    }

    public function testALinkToTheRestOfTheProjectIsRefused(): void
    {
        $this->link($this->dir . '/project/.env.local', $this->dir . '/project/files/env.txt');
        $this->link($this->dir . '/project/config', $this->dir . '/project/files/conf');

        self::assertFalse(PathJail::contains($this->dir . '/project', 'files', $this->dir . '/project/files/env.txt'));
        self::assertFalse(PathJail::contains($this->dir . '/project', 'files', $this->dir . '/project/files/conf/new.yaml'));
    }

    public function testALinkedRootIsTheRoot(): void
    {
        $this->link($this->dir . '/shared/files', $this->dir . '/project/linked');

        self::assertTrue(PathJail::contains($this->dir . '/project', 'linked', $this->dir . '/project/linked/x.jpg'));
    }

    public function testALinkWhoseTargetIsMissingIsRefused(): void
    {
        $this->link($this->dir . '/project/config/new.yaml', $this->dir . '/project/files/new.yaml');

        self::assertFalse(PathJail::contains($this->dir . '/project', 'files', $this->dir . '/project/files/new.yaml'));
    }

    public function testAPathOutsideTheRootIsRefusedBeforeAnyLookup(): void
    {
        self::assertFalse(PathJail::contains($this->dir . '/project', 'templates', $this->dir . '/project/templatesX/a.html5'));
        self::assertFalse(PathJail::contains($this->dir . '/project', 'files', $this->dir . '/project/config/x.yaml'));
    }

    public function testAMissingRootIsCreatedOnlyInTheProject(): void
    {
        self::assertTrue(PathJail::contains($this->dir . '/project', 'templates', $this->dir . '/project/templates/fe_page.html5'));
        self::assertFalse(PathJail::contains($this->dir . '/project', 'templates', $this->dir . '/project/config/x.yaml'));
    }
}
