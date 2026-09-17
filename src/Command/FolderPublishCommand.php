<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Folder;
use Contao\System;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Path;

/**
 * Make a folder public, or protect it again — the back end's five steps.
 *
 * Until v0.13.0 there was no way to do this from the CLI: `--public` on folder
 * create was removed on 2026-04-19 because it wrote to a column that does not
 * exist, and Contao's real mechanism was never built in its place. Without it the
 * front end does not serve the folder. See FolderPublishCommandTest.
 *
 * Mirrors the `protected` field of tl_files (5.7.13):
 *
 *  1. `Folder::isUnprotected()` — public already, itself or through a parent?
 *  2. a parent is public and this folder has no `.public` of its own → the back
 *     end disables the checkbox (contao/contao#712); here that is an error, so a
 *     caller learns why nothing changed
 *  3. `Folder::unprotect()` / `Folder::protect()`
 *  4. the symlinks, as `Automator::generateSymlinks()` makes them (its command, with the
 *     operator in the log context) — the web dir follows only after this
 *  5. the files channel: `Folder "…" has been published` / `… protected`
 */
#[AsCommand(name: 'contao:folder:publish', description: 'Make a folder public, or protect it again with --unpublish')]
class FolderPublishCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function systemLogAction(): string
    {
        return ContaoContext::FILES;
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Folder path relative to Contao root, e.g. files/conpai')
            ->addOption('unpublish', null, InputOption::VALUE_NONE, 'Protect the folder again instead of publishing it');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = trim(str_replace('\\', '/', (string) $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must be a folder below files/ and must not contain ".." (outside-files-root)');
        }

        $absPath  = rtrim($this->projectDir, '/') . '/' . $path;
        $jailRoot = realpath($this->projectDir);
        $realPath = realpath($absPath);

        // `new Folder()` creates a missing directory — check before constructing it.
        if (false === $realPath || !is_dir($realPath)) {
            return $this->outputError("Folder not found: {$path}. Create it first with contao:folder:create.");
        }
        if (false === $jailRoot || !str_starts_with($realPath . DIRECTORY_SEPARATOR, $jailRoot . DIRECTORY_SEPARATOR)) {
            return $this->outputError('Access denied: path resolves outside allowed directory');
        }

        $this->framework->initialize();

        $publish   = !$this->input->getOption('unpublish');
        $folder    = new Folder($path);
        $ownPublic = is_file($absPath . '/.public');
        $isPublic  = $folder->isUnprotected();

        // (2) Public through a parent: nothing here can change it.
        if ($isPublic && !$ownPublic) {
            return $this->outputError(
                "Folder {$path} is public because a parent folder is. "
                . 'Change the parent instead — the back end disables this setting for the same reason.'
            );
        }

        $changed = false;

        if ($publish && !$isPublic) {
            $folder->unprotect();
            $changed = true;
        } elseif (!$publish && $isPublic) {
            $folder->protect();
            $changed = true;
        }

        if ($changed) {
            // Automator::generateSymlinks(), with the same command and messages — but its
            // log lines carry no context, and on the console Contao fills in FE / N/A
            // (live on web.werk.wien, 2026-09-17, Nr. 59). In the back end the processor
            // takes the editor from the session; here the operator is passed along.
            $container = System::getContainer();
            $webDir    = Path::makeRelative($container->getParameter('contao.web_dir'), $container->getParameter('kernel.project_dir'));
            $status    = $container->get('contao.command.symlinks')->run(new ArgvInput(['', $webDir]), new NullOutput());

            if ($status > 0) {
                $container->get('monolog.logger.contao.error')->error('The symlinks could not be regenerated', $this->logContext(ContaoContext::ERROR));
            } else {
                $container->get('monolog.logger.contao.cron')->info('Regenerated the symlinks', $this->logContext(ContaoContext::CRON));
            }

            $container->get('monolog.logger.contao.files')->info(
                $publish
                    ? 'Folder "' . $path . '" has been published'
                    : 'Folder "' . $path . '" has been protected',
                $this->logContext(ContaoContext::FILES),
            );
        }

        $this->outputSuccess(['path' => $path, 'public' => $publish, 'changed' => $changed]);
        return Command::SUCCESS;
    }
}
