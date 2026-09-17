<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FileMoveCommand;

/**
 * A file or folder is moved into another folder the way the back end moves it.
 *
 * 🟡 **The gap, found on 2026-09-17** in the ConpAI 1.0 acceptance test (web.werk.wien):
 * the site's files were to go from `files/conpai` into `files/conpai-consho/layout`. There
 * was no command for it — deleting and writing anew changes the UUID, and every image
 * element pointing at the file renders nothing (Nr. 64).
 *
 * ## What Contao does (`DC_Folder::cut()`, 5.7.13 and 6.0.0; 5.3 the same with `Dbafs::moveResource()`)
 *
 * 1. refuse a circular move (a folder into itself) and a target that exists
 * 2. `Files::rename()`
 * 3. then the database: `contao.filesystem.dbafs_manager->sync($source, $destination)`, which
 *    recognises the move and keeps the UUID
 * 4. a folder: `Automator::generateSymlinks()`
 * 5. `monolog.logger.contao.files`: *File or folder "…" has been moved to "…"*
 *
 * The target is a folder, as with cut and paste; the name stays. These tests pin the
 * refusals that need no framework; the move is verified live on 5.3, 5.7 and 6.0.
 */
class FileMoveCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/file-move-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/files/x/sub', 0777, true);
        mkdir($this->root . '/files/y', 0777, true);
        file_put_contents($this->root . '/files/x/a.png', 'png');
        file_put_contents($this->root . '/files/y/a.png', 'other');
    }

    protected function tearDown(): void
    {
        foreach (['files/x/a.png', 'files/y/a.png', 'files/y/b.png', 'files/z'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        foreach (['files/x/sub', 'files/x', 'files/y', 'files', ''] as $dir) {
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

        $tester = new CommandTester(new FileMoveCommand($framework, $this->createMock(Connection::class), $this->root));
        $tester->execute($input);

        return json_decode($tester->getDisplay(), true);
    }

    /**
     * @dataProvider incomplete
     *
     * @param array<string, string> $input
     */
    public function testBothPathsAreRequired(array $input, string $option): void
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
            'no source' => [['--to' => 'files/y'], '--path'],
            'no target' => [['--path' => 'files/x/a.png'], '--to'],
        ];
    }

    /**
     * @dataProvider outside
     */
    public function testPathsOutsideFilesAreRefused(string $path, string $to): void
    {
        $out = $this->execute(['--path' => $path, '--to' => $to]);

        $this->assertSame('error', $out['status']);
        $this->assertFileExists($this->root . '/files/x/a.png');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function outside(): array
    {
        return [
            'source outside'    => ['templates/a.html.twig', 'files/y'],
            'files itself'      => ['files', 'files/y'],
            'dot-dot source'    => ['files/x/../x/a.png', 'files/y'],
            'target outside'    => ['files/x/a.png', 'templates'],
            'dot-dot target'    => ['files/x/a.png', 'files/y/../../templates'],
        ];
    }

    /**
     * files/ itself is a valid target, as the back end's paste into the root is.
     *
     * @dataProvider targets
     */
    public function testTheTargetFolderIsCanonicalised(string $to, ?string $expected): void
    {
        $this->assertSame($expected, FileMoveCommand::canonicalFolder($to));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function targets(): array
    {
        return [
            'files root'        => ['files', 'files'],
            'trailing slash'    => ['files/y/', 'files/y'],
            'double slash'      => ['files//y', 'files/y'],
            'outside'           => ['templates', null],
            'dot-dot'           => ['files/../templates', null],
        ];
    }

    public function testAMissingSourceIsRefused(): void
    {
        $out = $this->execute(['--path' => 'files/x/gibtesnicht.png', '--to' => 'files/y']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not found', $out['message']);
    }

    public function testATargetThatIsNoFolderIsRefused(): void
    {
        $out = $this->execute(['--path' => 'files/x/sub', '--to' => 'files/y/a.png']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not a folder', $out['message']);
    }

    /**
     * Contao: *"Attempt to move the folder … (circular reference)"*.
     */
    public function testAFolderCannotMoveIntoItself(): void
    {
        $out = $this->execute(['--path' => 'files/x', '--to' => 'files/x/sub']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('into itself', $out['message']);
        $this->assertDirectoryExists($this->root . '/files/x/sub');
    }

    public function testMovingIntoTheFolderItIsInChangesNothing(): void
    {
        $out = $this->execute(['--path' => 'files/x/a.png', '--to' => 'files/x']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('already in', $out['message']);
    }

    /**
     * Contao does not overwrite: `$GLOBALS['TL_LANG']['ERR']['filetarget']`.
     */
    public function testAnExistingTargetIsNotOverwritten(): void
    {
        $out = $this->execute(['--path' => 'files/x/a.png', '--to' => 'files/y']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('already exists', $out['message']);
        $this->assertSame('other', file_get_contents($this->root . '/files/y/a.png'));
        $this->assertFileExists($this->root . '/files/x/a.png');
    }

    /**
     * Review before v0.23.0: the DBAFS manager refuses to sync a path whose first segment
     * below files/ starts with a dot ("Dot path … is not allowed") — after the rename the
     * file would be moved and tl_files stale. Refused before anything moves, both ways.
     *
     * @dataProvider dotPaths
     */
    public function testATopLevelDotPathIsRefusedBeforeTheMove(string $path, string $to): void
    {
        mkdir($this->root . '/files/.hidden');

        try {
            $out = $this->execute(['--path' => $path, '--to' => $to]);
        } finally {
            @rmdir($this->root . '/files/.hidden/sub');
            @rmdir($this->root . '/files/.hidden');
        }

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('dot', $out['message']);
        $this->assertDirectoryExists($this->root . '/files/x/sub');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dotPaths(): array
    {
        return [
            'source'      => ['files/.hidden', 'files/y'],
            'destination' => ['files/x/sub', 'files/.hidden'],
        ];
    }

    /**
     * A `.public` file decides whether a folder is served; moving it changes two folders'
     * protection behind the back of folder:publish.
     */
    public function testThePublicMarkerCannotBeMoved(): void
    {
        touch($this->root . '/files/x/.public');

        try {
            $out = $this->execute(['--path' => 'files/x/.public', '--to' => 'files/y']);
        } finally {
            @unlink($this->root . '/files/x/.public');
        }

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('folder-publish', $out['message']);
    }

    public function testAPathThroughALinkedFolderIsRefused(): void
    {
        if (!@symlink($this->root . '/files/y', $this->root . '/files/z')) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }

        try {
            $out = $this->execute(['--path' => 'files/x/sub', '--to' => 'files/z']);
        } finally {
            is_dir($this->root . '/files/z') && '\\' === \DIRECTORY_SEPARATOR
                ? @rmdir($this->root . '/files/z')
                : @unlink($this->root . '/files/z');
        }

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('symbolic link', $out['message']);
        $this->assertDirectoryExists($this->root . '/files/x/sub');
    }
}
