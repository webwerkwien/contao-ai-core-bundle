<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FileDeleteCommand;

/**
 * A file or folder is deleted the way the back end deletes it — and not while it is used.
 *
 * 🔴 **The gap, found on 2026-09-16** (Nr. 46 of the ConpAI 1.0 acceptance test): three
 * probe images on c5 could not be removed. Neither the core-bundle nor the CLI had a way
 * to delete a file; no decision against it was ever recorded.
 *
 * ## What Contao does (`DC_Folder::delete()`, identical in 5.7.13 and 6.0.0)
 *
 * 1. `Files::rrdir()` for a folder — and its symlink in the web dir — or `Files::delete()`
 * 2. `purgeCache()`: the script cache for css/js
 * 3. then the database: `contao.filesystem.dbafs_manager->sync()`, which compares tl_files
 *    with what is left on disk (5.3 still calls `Dbafs::deleteResource()`)
 * 4. `monolog.logger.contao.files`: *File or folder "…" has been deleted* — here the
 *    bundle's own CLI log entry takes its place, with the operator (one entry, not two)
 *
 * What Contao does not do, and this command does: refuse a file that is still referenced
 * (FileUsageFinderTest). There is no `tl_undo` for files, so a deleted file is gone.
 *
 * These tests pin the refusals that need no framework; the deletion is verified live.
 */
class FileDeleteCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/file-delete-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/files/x', 0777, true);
        file_put_contents($this->root . '/files/x/a.png', 'png');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/files/x/a.png');
        @rmdir($this->root . '/files/x');
        @rmdir($this->root . '/files');
        @rmdir($this->root);
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

        $tester = new CommandTester(new FileDeleteCommand($framework, $this->createMock(Connection::class), $this->root));
        $tester->execute($input);

        return json_decode($tester->getDisplay(), true);
    }

    public function testMissingPathReturnsAnError(): void
    {
        $out = $this->execute([]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--path', $out['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'the files root'      => ['files'],
            'the files root, /'   => ['files/'],
            'outside files'       => ['system/config/localconfig.php'],
            'parent traversal'    => ['files/../system/config/localconfig.php'],
            'traversal in a name' => ['files/x/../../composer.json'],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testAPathOutsideFilesOrTheRootItselfIsRefused(string $path): void
    {
        $out = $this->execute(['--path' => $path]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('below files/', $out['message']);
    }

    /**
     * 🔴 Review 2026-09-16, reproduced on the Contao 6 test install: `files/rv/b//a.txt`
     * deleted the file, found no tl_files record by that string, so skipped the UUID search
     * and left the record behind — while answering `records: 1`.
     *
     * @return array<string, array{string, string|null}>
     */
    public static function canonicalPaths(): array
    {
        return [
            'already canonical' => ['files/a/b.png', 'files/a/b.png'],
            'double slash'      => ['files/a//b.png', 'files/a/b.png'],
            'dot segment'       => ['files/a/./b.png', 'files/a/b.png'],
            'leading ./'        => ['./files/a/b.png', 'files/a/b.png'],
            'backslashes'       => ['files\\a\\b.png', 'files/a/b.png'],
            'trailing slash'    => ['files/a/', 'files/a'],
            'traversal'         => ['files/a/../b.png', null],
            'files itself'      => ['files//', null],
            'outside files'     => ['system/config', null],
            'files2'            => ['files2/a.png', null],
        ];
    }

    /**
     * @dataProvider canonicalPaths
     */
    public function testThePathIsCanonicalisedBeforeAnythingIsLookedUp(string $path, ?string $expected): void
    {
        $this->assertSame($expected, FileDeleteCommand::canonicalPath($path));
    }

    /**
     * 🔴 Review 2026-09-16, reproduced on the Contao 6 test install: `files/rv/link` pointed
     * at `files/rv/real`. `rrdir` emptied `real/`, the link stayed, and the answer said
     * nothing had changed. A link is now removed as a link; a path *through* one is refused.
     */
    public function testAPathThroughALinkedFolderIsRefused(): void
    {
        if (!@symlink($this->root . '/files/x', $this->root . '/files/link')) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }

        try {
            $out = $this->execute(['--path' => 'files/link/a.png']);
        } finally {
            is_dir($this->root . '/files/link') && '\\' === \DIRECTORY_SEPARATOR
                ? @rmdir($this->root . '/files/link')
                : @unlink($this->root . '/files/link');
        }

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('symbolic link', $out['message']);
        $this->assertFileExists($this->root . '/files/x/a.png');
    }

    public function testAMissingFileIsRefusedBeforeAnythingElse(): void
    {
        $out = $this->execute(['--path' => 'files/x/gibtesnicht.png']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not found', $out['message']);
    }
}
