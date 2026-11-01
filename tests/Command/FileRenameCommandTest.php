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
            'quote'         => ['c".png'],
            'leading dot'   => ['.hidden.png'],
            'the marker'    => ['.public'],
            'htaccess'      => ['.htaccess'],
            'trailing dot'  => ['c.png.'],
            'too long'      => [str_repeat('a', 252) . '.png'],
        ];
    }

    /**
     * For a folder the trailing dot is the only rule that applies — no extension to keep.
     */
    public function testAFolderNameMustNotEndWithADot(): void
    {
        $out = $this->execute(['--path' => 'files/x', '--name' => 'y.']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('must not end with a dot', $out['message']);
        $this->assertDirectoryExists($this->root . '/files/x');
    }

    public function testOrdinaryNamesPassTheNameCheck(): void
    {
        // The known non-match for the refusals above: a check that refuses everything
        // passes those tests just as well.
        foreach (['c.png', 'Über uns.png', 'a.b.png', 'a b', str_repeat('a', 251) . '.png'] as $name) {
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
     * A `.public` file decides whether a folder is served; renaming it would change that
     * behind the back of folder:publish. Renaming something to it is a leading dot, see
     * badNames.
     */
    public function testThePublicMarkerIsNotRenamed(): void
    {
        touch($this->root . '/files/x/.public');

        $out = $this->execute(['--path' => 'files/x/.public', '--name' => 'c.public']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('folder-publish', $out['message']);
        $this->assertFileExists($this->root . '/files/x/.public');
    }

    /**
     * The file type stays: refused before anything changes or the framework starts.
     */
    public function testAFileKeepsItsExtension(): void
    {
        $out = $this->execute(['--path' => 'files/x/a.png', '--name' => 'a.php']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('ending in .png', $out['message']);
        $this->assertStringContainsString('Nothing was renamed', $out['message']);
        $this->assertFileExists($this->root . '/files/x/a.png');
        $this->assertFileDoesNotExist($this->root . '/files/x/a.php');
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
     * As the back end's rename (Contao #6641): the thumbnails of the image under its old
     * name go, those of other images stay. A folder has none.
     */
    public function testARenamedImageLosesItsOldThumbnails(): void
    {
        $assets = $this->root . '/assets/images';
        mkdir($assets . '/1', 0777, true);
        mkdir($assets . '/2', 0777, true);
        $thumbnails = [$assets . '/1/a-1234abcd.png', $assets . '/2/a-5678ef01.png', $assets . '/1/a[b]-1234abcd.png'];
        $others     = [$assets . '/1/ab-1234abcd.png', $assets . '/1/b-1234abcd.png', $assets . '/2/a-5678ef01.jpg'];

        foreach ([...$thumbnails, ...$others] as $file) {
            file_put_contents($file, 'thumb');
        }

        // Only files go, as with Files::delete(): a folder that happens to match stays.
        mkdir($assets . '/2/a-folder.png');
        $others[] = $assets . '/2/a-folder.png';

        $previous  = \Contao\System::getContainer();
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->setParameter('contao.image.valid_extensions', ['jpg', 'png', 'svg']);
        $container->setParameter('contao.image.target_dir', $assets);
        \Contao\System::setContainer($container);

        try {
            $command     = new FileRenameCommand($this->createMock(ContaoFramework::class), $this->createMock(Connection::class), $this->root);
            $afterRename = new \ReflectionMethod($command, 'afterRename');

            $this->assertSame(['thumbnailsRemoved' => 2], $afterRename->invoke($command, 'files/x/a.png', 'files/x/c.png', false));

            // [b] is part of the name, not a glob class: a[b]-… goes, ab-… stays.
            $this->assertSame(['thumbnailsRemoved' => 1], $afterRename->invoke($command, 'files/x/a[b].png', 'files/x/c.png', false));
            $this->assertSame([], $afterRename->invoke($command, 'files/x/sub', 'files/x/sub2', true));
        } finally {
            (new \ReflectionProperty(\Contao\System::class, 'objContainer'))->setValue(null, $previous);
            (new \ReflectionProperty(\Contao\System::class, 'arrStaticObjects'))->setValue(null, []);
        }

        foreach ($thumbnails as $file) {
            $this->assertFileDoesNotExist($file);
        }
        foreach ($others as $file) {
            $this->assertFileExists($file);
        }

        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root . '/assets');
    }

    /**
     * As in the back end, a file keeps its extension exactly as written: a rename changes
     * the name, not the file type. So `a.txt` never becomes `a.php`, whatever is allowed.
     *
     * @dataProvider extensions
     */
    public function testAFileKeepsItsExtensionAsWritten(string $source, string $name, bool $isFolder, bool $refused): void
    {
        $reason = FileRenameCommand::extensionRefusal($source, $name, $isFolder);

        if ($refused) {
            $this->assertNotNull($reason, "{$source} -> {$name}");
        } else {
            $this->assertNull($reason, "{$source} -> {$name}");
        }
    }

    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function extensions(): array
    {
        return [
            'same extension'           => ['files/a.png', 'b.png', false, false],
            'name case only'           => ['files/a.png', 'A.png', false, false],
            'more dots in the name'    => ['files/a.png', 'a.v2.png', false, false],
            'old file, old type'       => ['files/a.exe', 'b.exe', false, false],
            'txt to php'               => ['files/a.txt', 'a.php', false, true],
            'another allowed type'     => ['files/a.png', 'a.jpg', false, true],
            'case of the extension'    => ['files/x.PHP', 'x.php', false, true],
            'extension removed'        => ['files/a.png', 'a', false, true],
            'extension added'          => ['files/README', 'README.php', false, true],
            'no extension, none added' => ['files/README', 'NOTES', false, false],
            'a folder is free'         => ['files/sub', 'sub.v2', true, false],
        ];
    }
}
