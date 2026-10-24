<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Terminal42\ChangeLanguage\PageFinder;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\ChangeLanguageLinks;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LinkTargetDiagnosis;

/**
 * Which translation links are missing or broken (terminal42/contao-changelanguage). Reads only.
 *
 * Per root page of another language, against the tree of its main language (the
 * package's own PageFinder decides which that is):
 *
 * - `unlinked` — pages without `languageMain`. The language switcher falls back to the
 *   root for them, and no `hreflang` alternate is output.
 * - `invalid` — a `languageMain` that points outside the main tree, or to nothing.
 * - `duplicates` — two pages of one tree linked to the same page; the package refuses
 *   that on save, older data can still have it.
 * - `unlinkedArticles` — articles on a linked page without `languageMain`.
 * - `unlinkableArticles` — such articles that cannot be linked, each with the reason: the
 *   page in the main language or its root is unpublished, so changelanguage offers no
 *   target, in the back end either (v1.11.1).
 *
 * And per archive table that is installed: archives whose jumpTo page is in another
 * language's tree without `master`, and entries of a linked archive without
 * `languageMain`. A missing `master` is the half that is easy to forget: the entry
 * links look right in the database and the switcher still falls back.
 *
 * Lists are capped at 50 IDs; the counts are complete.
 */
#[AsCommand(name: 'contao:language:check', description: 'Report missing or broken translation links (changelanguage); reads only')]
class LanguageCheckCommand extends Command
{
    use JsonErrorBoundary;

    private const CAP = 50;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LinkTargetDiagnosis $diagnosis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Only this root page (of a language other than the main one)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, fn (): int => $this->doExecute($input, $output));
    }

    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $only   = (int) $input->getOption('root');
        $finder = new PageFinder();
        $db     = Database::getInstance();
        $roots  = [];

        foreach ($this->connection->fetchFirstColumn("SELECT id FROM tl_page WHERE type = 'root' ORDER BY sorting") as $rootId) {
            if ($only > 0 && (int) $rootId !== $only) {
                continue;
            }

            $root   = PageModel::findWithDetails((int) $rootId);
            $master = null === $root ? null : $finder->findMasterRootForPage($root);

            if (null === $root || null === $master || (int) $master->id === (int) $root->id) {
                continue;
            }

            $masterTree = array_map('intval', $db->getChildRecords((int) $master->id, 'tl_page'));
            $tree       = array_map('intval', $db->getChildRecords((int) $root->id, 'tl_page'));
            $pages      = [] === $tree ? [] : $this->connection->fetchAllAssociative(
                'SELECT id, languageMain FROM tl_page WHERE id IN (' . implode(',', $tree) . ')',
            );

            $unlinked = $invalid = $byMain = [];

            foreach ($pages as $page) {
                $main = (int) $page['languageMain'];

                if (0 === $main) {
                    $unlinked[] = (int) $page['id'];
                } elseif (!\in_array($main, $masterTree, true)) {
                    $invalid[] = ['id' => (int) $page['id'], 'languageMain' => $main];
                } else {
                    $byMain[$main][] = (int) $page['id'];
                }
            }

            $duplicates = [];

            foreach ($byMain as $main => $ids) {
                if (\count($ids) > 1) {
                    $duplicates[] = ['languageMain' => $main, 'ids' => $ids];
                }
            }

            $linked   = array_merge(...array_values($byMain) ?: [[]]);
            $articles = [] === $linked ? [] : array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_article WHERE languageMain = 0 AND pid IN (' . implode(',', $linked) . ')',
            ));

            // An article whose counterpart page or root is unpublished cannot be linked —
            // changelanguage offers no target, in the back end either. Reported apart with
            // the reason, not as a gap to close (v1.11.1, e.g. resource pages).
            ['closable' => $closable, 'unlinkable' => $unlinkable] = $this->diagnosis->forArticles($articles);

            $roots[] = [
                'root'             => ['id' => (int) $root->id, 'title' => $root->title, 'language' => $root->language, 'dns' => $root->dns],
                'mainRoot'         => ['id' => (int) $master->id, 'title' => $master->title, 'language' => $master->language],
                'pages'            => \count($pages),
                'linked'           => \count($linked),
                'unlinked'         => $this->capped($unlinked),
                'invalid'          => \array_slice($invalid, 0, self::CAP),
                'duplicates'       => $duplicates,
                'unlinkedArticles' => $this->capped($closable),
                'unlinkableArticles' => ['count' => \count($unlinkable), 'articles' => \array_slice($unlinkable, 0, self::CAP)],
                'archives'         => $this->archives($tree),
            ];
        }

        $output->writeln(json_encode(['status' => 'ok', 'roots' => $roots], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * @param list<int> $tree the pages of one language's tree
     *
     * @return list<array<string, mixed>>
     */
    private function archives(array $tree): array
    {
        if ([] === $tree) {
            return [];
        }

        $schema = $this->connection->createSchemaManager();
        $found  = [];

        foreach (ChangeLanguageLinks::ARCHIVES as $archiveTable => $childTable) {
            if (!$schema->tablesExist([$archiveTable, $childTable]) || !isset($schema->listTableColumns($archiveTable)['master'])) {
                continue;
            }

            $archives = $this->connection->fetchAllAssociative(
                'SELECT id, title, master FROM ' . $archiveTable . ' WHERE jumpTo IN (' . implode(',', $tree) . ')',
            );

            foreach ($archives as $archive) {
                $entry = ['table' => $archiveTable, 'id' => (int) $archive['id'], 'title' => $archive['title'], 'master' => (int) $archive['master']];

                if (0 === (int) $archive['master']) {
                    $entry['problem'] = 'no master: the switcher falls back to the root for every entry, whatever their own links say';
                } else {
                    $entry['unlinkedEntries'] = $this->capped(array_map('intval', $this->connection->fetchFirstColumn(
                        'SELECT id FROM ' . $childTable . ' WHERE pid = ? AND languageMain = 0',
                        [(int) $archive['id']],
                    )));
                }

                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     *
     * @return array{count: int, ids: list<int>}
     */
    private function capped(array $ids): array
    {
        return ['count' => \count($ids), 'ids' => \array_slice($ids, 0, self::CAP)];
    }
}
