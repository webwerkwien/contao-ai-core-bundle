<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FileRenameCommand;

/**
 * A file or folder is renamed in place and keeps its UUID.
 *
 * 🟡 **The gap:** the bundle could move and delete files, but not rename them. Deleting
 * and writing under the new name gives a new UUID, and every element pointing at the old
 * one renders nothing.
 *
 * `contao:file:rename` shares the rename and the DBAFS sync with `contao:file:move`. These
 * tests pin the refusals that need no framework and the extension rule; the rename itself
 * is verified on a real installation.
 */
class FileRenameCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/file-rename-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/files/x/sub', 0777, true);
        file_put_contents($this->root . '/files/x/a.png', 'png');
        file_put_contents($this->root . '/files/x/b.png', 'other');
    }

    protected function tearDown(): void
    {
        foreach (['files/x/a.png', 'files/x/b.png', 'files/x/.public', 'files/x/c.png', 'files/z'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        foreach (['files/x/sub', 'files/x', 'files', ''] as $dir) {
            @rmdir($this->root . '/' . $dir);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function execute(array $input): array
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new FileRenameCommand($framework, $this->createMock(Connection::class), $this->root));
        $tester->execute($input);

        return json_decode($tester->getDisplay(), true);
    }

    /**
     * @dataProvider incomplete
     *
     * @param array<string, string> $input
     */
    public function testBothOptionsAreRequired(array $input, string $option): void
    {
        $out = $this->execute($input);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString($option, $out['message']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function incomplete(): array
    {
        return [
            'no path' => [['--name' => 'c.png'], '--path'],
            'no name' => [['--path' => 'files/x/a.png'], '--name'],
        ];
    }

    /**
     * @dataProvider outside
     */
    public function testPathsOutsideFilesAreRefused(string $path): void
    {
        $out = $this->execute(['--path' => $path, '--name' => 'c.png']);

        $this->assertSame('error', $out['status']);
        $this->assertFileExists($this->root . '/files/x/a.png');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function outside(): array
    {
        return [
            'outside'   => ['templates/a.html.twig'],
            'files'     => ['files'],
            'dot-dot'   => ['files/x/../x/a.png'],
        ];
    }

    /**
     * A name, not a path: anything with a separator would move the file instead.
     *
     * @dataProvider badNames
     */
    public function testANameThatIsNoNameIsRefused(string $name): void
    {
        $out = $this->execute(['--path' => 'files/x/a.png', '--name' => $name]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('Nothing was renamed', $out['message']);
        $this->assertFileExists($this->root . '/files/x/a.png');
        $this->assertFileDoesNotExist($this->root . '/files/c.png');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badNames(): array
    {
        return [
            'slash'         => ['sub/c.png'],
            'backslash'     => ['sub\\c.png'],
            'dot-dot'       => ['..'],
            'dot'           => ['.'],
            'up and away'   => ['../c.png'],
            'control char'  => ["c\x00.png"],
            'too long'      => [str_repeat('a', 256)],
        ];
    }

    public function testOrdinaryNamesPassTheNameCheck(): void
    {
        // The known non-match for the refusals above: a check that refuses everything
        // passes those tests just as well.
        foreach (['c.png', 'Über uns.png', '.hidden', 'a b', str_repeat('a', 255)] as $name) {
            $this->assertNull(FileRenameCommand::nameRefusal($name), $name);
        }
    }

    public function testAMissingSourceIsRefused(): void
    {
        $out = $this->execute(['--path' => 'files/x/gibtesnicht.png', '--name' => 'c.png']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not found', $out['message']);
    }

    public function testTheSameNameChangesNothing(): void
    {
        $out = $this->execute(['--path' => 'files/x/a.png', '--name' => 'a.png']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('already has this name', $out['message']);
    }

    /**
     * Contao does not overwrite.
     */
    public function testAnExistingNameIsNotOverwritten(): void
    {
        $out = $this->execute(['--path' => 'files/x/a.png', '--name' => 'b.png']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('already exists', $out['message']);
        $this->assertSame('other', file_get_contents($this->root . '/files/x/b.png'));
        $this->assertSame('png', file_get_contents($this->root . '/files/x/a.png'));
    }

    /**
     * The DBAFS manager refuses a first segment below files/ that starts with a dot; a
     * rename that creates one is refused before anything changes.
     */
    public function testATopLevelDotNameIsRefused(): void
    {
        mkdir($this->root . '/files/y');

        try {
            $out = $this->execute(['--path' => 'files/y', '--name' => '.hidden']);
        } finally {
            @rmdir($this->root . '/files/y');
        }

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('dot', $out['message']);
    }

    /**
     * A `.public` file decides whether a folder is served; renaming it, or renaming
     * something to it, would change that behind the back of folder:publish.
     *
     * @dataProvider publicMarker
     */
    public function testThePublicMarkerIsNeitherRenamedNorCreated(string $path, string $name): void
    {
        touch($this->root . '/files/x/.public');

        $out = $this->execute(['--path' => $path, '--name' => $name]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('folder-publish', $out['message']);
        $this->assertFileExists($this->root . '/files/x/.public');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function publicMarker(): array
    {
        return [
            'rename it'    => ['files/x/.public', 'c.png'],
            'rename to it' => ['files/x/a.png', '.public'],
        ];
    }

    public function testAPathThroughALinkedFolderIsRefused(): void
    {
        if (!@symlink($this->root . '/files/x', $this->root . '/files/z')) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }

        try {
            $out = $this->execute(['--path' => 'files/z/a.png', '--name' => 'c.png']);
        } finally {
            is_dir($this->root . '/files/z') && '\\' === \DIRECTORY_SEPARATOR
                ? @rmdir($this->root . '/files/z')
                : @unlink($this->root . '/files/z');
        }

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('symbolic link', $out['message']);
        $this->assertFileExists($this->root . '/files/x/a.png');
    }

    /**
     * A rename must not open what a write refuses: the extension the web server runs, and
     * one `uploadTypes` does not list. The same extension passes in any case.
     *
     * @dataProvider extensions
     */
    public function testANewExtensionIsHeldAgainstTheUploadRules(string $source, string $destination, bool $refused): void
    {
        $command = new FileRenameCommand($this->createMock(ContaoFramework::class), $this->createMock(Connection::class), $this->root);

        $reason = $command->extensionRefusal($source, $destination, 'jpg,png,svg');

        if ($refused) {
            $this->assertNotNull($reason, "{$source} -> {$destination}");
            $this->assertStringContainsString('Nothing was renamed', (string) $reason);
        } else {
            $this->assertNull($reason, "{$source} -> {$destination}");
        }
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function extensions(): array
    {
        return [
            'same extension'                  => ['files/a.png', 'files/b.png', false],
            'case only'                       => ['files/a.png', 'files/A.PNG', false],
            'another listed extension'        => ['files/a.png', 'files/a.jpg', false],
            'php'                             => ['files/a.png', 'files/a.php', true],
            'phtml'                           => ['files/a.png', 'files/a.phtml', true],
            'htaccess'                        => ['files/a.png', 'files/.htaccess', true],
            'user.ini from another ini'       => ['files/a.ini', 'files/.user.ini', true],
            'extension not in uploadTypes'    => ['files/a.png', 'files/a.exe', true],
            'extension removed'               => ['files/a.png', 'files/a', true],
            'an old file keeps its extension' => ['files/a.exe', 'files/b.exe', false],
        ];
    }
}
