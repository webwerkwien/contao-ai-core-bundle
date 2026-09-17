<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FolderPublishCommand;

/**
 * A folder is made public — or protected again — the way the back end does it.
 *
 * 🔴 **The gap, found on 2026-09-16** in the ConpAI 1.0 acceptance test: there was no
 * way to publish a folder from the CLI. `folder create --public` was removed on
 * 2026-04-19 because it wrote to a `tl_files.public` column that does not exist;
 * the mechanism Contao actually uses was never built instead. Without it the front
 * end does not serve `files/conpai/…`, and a site cannot be built through the CLI.
 *
 * ## What Contao does (tl_files.php, 5.7.13, the `protected` field's input callback)
 *
 * 1. `Folder::isUnprotected()` — is this folder or a parent already public?
 * 2. A parent is public and this folder has no `.public` of its own → nothing can be
 *    changed here; the back end disables the checkbox (see contao/contao#712)
 * 3. publish: `Folder::unprotect()` touches `.public`; protect: `Folder::protect()`
 * 4. `Automator::generateSymlinks()` — the web dir only follows after this (the command
 *    runs the same `contao.command.symlinks` itself, to log with the operator, Nr. 59)
 * 5. `monolog.logger.contao.files`: *Folder "…" has been published* / *protected*
 *
 * The command does the same five steps. Step 2 is answered with an error instead of
 * silence, so a caller learns why nothing changed.
 */
class FolderPublishCommandTest extends TestCase
{
    /**
     * Both lines Contao's channels write for a publish carry the CLI context (Nr. 59).
     * `Automator::generateSymlinks()` logs without one, so it is replaced by the same
     * `contao.command.symlinks` call with a context of our own.
     */
    public function testTheContaoLinesAreAttributedToTheOperator(): void
    {
        // Code only: the comments explain what replaced generateSymlinks() and name it.
        $source = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../../src/Command/FolderPublishCommand.php')) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $source .= \is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('generateSymlinks()', $source, 'Automator::generateSymlinks() logs as FE / N/A on the console');
        $this->assertStringContainsString("'contao.command.symlinks'", $source);
        // The published/protected line and the symlink line — success or error — each need it.
        $this->assertSame(3, substr_count($source, '$this->logContext('), 'every line written to a Contao channel needs the CLI context');
    }

    private function tester(ContaoFramework $framework): CommandTester
    {
        return new CommandTester(new FolderPublishCommand($framework, sys_get_temp_dir()));
    }

    public function testMissingPathReturnsAnError(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = $this->tester($framework);
        $tester->execute([]);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--path', $out['message']);
    }

    /**
     * @dataProvider outside
     */
    public function testAPathOutsideFilesIsRefused(string $path): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = $this->tester($framework);
        $tester->execute(['--path' => $path]);

        $this->assertSame('error', json_decode($tester->getDisplay(), true)['status']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function outside(): array
    {
        return [
            'traversal'      => ['files/../config'],
            'not under files' => ['templates/x'],
            'files itself'   => ['files'],
        ];
    }

    public function testTheCommandMirrorsTheBackEnd(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/FolderPublishCommand.php');

        foreach (['isUnprotected()', '->unprotect()', '->protect()', "'contao.command.symlinks'", 'Regenerated the symlinks', 'monolog.logger.contao.files', 'has been published', 'has been protected'] as $needle) {
            $this->assertStringContainsString($needle, $source, "missing the back end's step: {$needle}");
        }
    }

    public function testItHasAnUnpublishOption(): void
    {
        $command = new FolderPublishCommand($this->createMock(ContaoFramework::class), sys_get_temp_dir());

        $this->assertSame('contao:folder:publish', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('unpublish'));
    }
}
