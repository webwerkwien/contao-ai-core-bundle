<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Files\FileUsageFinder;

/**
 * Where a file, or anything below a folder, is used: the same search contao:file:delete
 * runs before it deletes, available on its own.
 *
 * Contao has no such view. It refers to files by UUID, so a rename or a move keeps those
 * references intact; only a path written into a text field breaks. `--paths-only`
 * searches for exactly these, which is what a caller asks before renaming or moving.
 */
#[AsCommand(name: 'contao:file:usages', description: 'List where a file or folder below files/ is used (UUID references and paths in text fields)')]
class FileUsagesCommand extends AbstractReadCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder path relative to Contao root, e.g. files/media/image.png')
            ->addOption('paths-only', null, InputOption::VALUE_NONE, 'Search only for the path in text fields — the references a rename or a move breaks');
    }

    protected function doExecute(): int
    {
        $raw = $this->input->getOption('path');
        if (!\is_string($raw) || '' === $raw) {
            return $this->outputError('--path is required');
        }

        $path = FileDeleteCommand::canonicalPath($raw);
        if (null === $path) {
            return $this->outputError('Path must lie below files/ and must not contain ".."');
        }

        // The DCA of every searched table is loaded below.
        $this->framework->initialize();

        $finder    = new FileUsageFinder($this->connection);
        $resources = $finder->resourcesOf($path);
        if ([] === $resources) {
            if (!file_exists(rtrim($this->projectDir, '/') . '/' . $path)) {
                return $this->outputError("Not found: {$path}");
            }
            // On disk but not synchronised: there is no UUID to look for, only the path.
            $resources = [['path' => $path, 'uuid' => null]];
        }

        $pathsOnly = (bool) $this->input->getOption('paths-only');
        $search    = $finder->find($pathsOnly ? FileUsageFinder::pathsOnly($resources) : $resources);

        $this->outputRecord([
            'path'          => $path,
            'pathsOnly'     => $pathsOnly,
            'usages'        => $search['usages'],
            'complete'      => \count($search['usages']) < FileUsageFinder::MAX_USAGES,
            'skippedTables' => $search['skippedTables'],
        ]);

        return 0;
    }
}
