<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Clones a tl_news_archive container plus all its tl_news children inside a
 * single DB transaction. Per-record version snapshots are written for the
 * archive AND every cloned news record (operator stamped) so the audit-trail
 * matches what the regular Contao backend produces on edit.
 *
 * Modifications-Allow-List: only `title` is overridable on the clone target
 * for now. Permission-related columns (protected, groups, jumpTo) stay verbatim
 * from the source so an editor can't escalate access by cloning a public
 * archive into a different group context. Open up later as use-cases appear.
 */
class NewsArchiveCloner implements EntityClonerInterface
{
    use CopiesSourceRows;
    use GeneratesUniqueAlias;
    use ClonesContentSubtree;
    use FiltersModifications;

    /**
     * Field allow-list for the `modifications` payload when cloning the
     * tl_news_archive root. Anything else is silently ignored.
     */
    private const ALLOWED_ARCHIVE_MODIFICATIONS = ['title'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_news_archive' === $table;
    }

    public function clone(int $sourceId, array $modifications, string $operator, array $options = []): array
    {
        // News-Archive haben keine container-of-container-Hierarchie — `recursive`
        // wird ignoriert. Signature-Kompat zur Interface-Erweiterung.
        $this->framework->initialize();

        $source = NewsArchiveModel::findById($sourceId);
        if (null === $source) {
            throw new \RuntimeException(\sprintf('News-Archiv %d nicht gefunden.', $sourceId));
        }

        ['accepted' => $filteredMods, 'ignored' => $ignoredMods] = $this->partitionModifications(
            $modifications,
            self::ALLOWED_ARCHIVE_MODIFICATIONS,
        );

        $authorId = $this->resolveAuthorId($operator);

        // Atomic cascade: archive + all children commit together or not at all.
        return $this->connection->transactional(function () use ($source, $sourceId, $filteredMods, $ignoredMods, $operator, $authorId): array {
            $newArchiveId = $this->cloneArchiveRow($source, $filteredMods);
            $this->versionManager->createVersion('tl_news_archive', $newArchiveId, $operator);

            $count    = 0;
            $contents = 0;
            $children = NewsModel::findBy('pid', $sourceId);
            if (null !== $children) {
                foreach ($children as $child) {
                    $newChildId = $this->cloneNewsRow($child, $newArchiveId, $authorId);
                    $this->versionManager->createVersion('tl_news', $newChildId, $operator);
                    ++$count;

                    // 🔴 H-5: Inhaltselemente unter dem Kind wurden nie mitkopiert.
                    // Der Löschpfad kaskadiert sie (RecordCascadeCollector: "tl_content
                    // hangs under articles, news and events"), der Klon ließ sie stehen
                    // und meldete trotzdem Erfolg.
                    $contents += $this->cloneContentSubtree((int) $child->id, $newChildId, 'tl_news', $operator);
                }
            }

            return [
                'id'    => $newArchiveId,
                'table' => 'tl_news_archive',
                'count' => $count,
                // H-5: getrennt ausgewiesen, damit eine leere Zahl auffaellt
                'contents' => $contents,
                // Overrides this cloner refused. Empty on a clean call; never omitted.
                'ignored_modifications' => $ignoredMods,
            ];
        });
    }

    /**
     * @param array<string, scalar|null> $modifications
     */
    private function cloneArchiveRow(NewsArchiveModel $source, array $modifications): int
    {
        $clone = new NewsArchiveModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_news_archive', (int) $source->id));
        $clone->tstamp = time();

        foreach ($modifications as $key => $value) {
            $clone->$key = (string) $value;
        }
        if (!isset($modifications['title'])) {
            $clone->title = ((string) ($source->title ?? '')) . ' (Kopie)';
        }

        $clone->save();
        return (int) $clone->id;
    }

    private function cloneNewsRow(NewsModel $source, int $newArchiveId, int $authorId): int
    {
        $clone = new NewsModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_news', (int) $source->id));
        $clone->tstamp    = time();
        $clone->pid       = $newArchiveId;
        $clone->author    = $authorId;
        // Cloned children land as drafts — operator should review/translate
        // before publishing. Matches NewsCreateCommand's default.
        $clone->published = '0';
        // 🔴 H-6: bis 2026-09-02 ohne Eindeutigkeitsprüfung. Model::save()
        // führt den DCA-save_callback nicht aus, also lief Contaos eigener
        // Alias-Check nie — und `tl_news.alias` trägt `unique=>true` samt
        // `doNotCopy=>true`. Zwei Datensätze mit `sommerfest`, und welchen eine
        // Alias-Auflösung erwischt, entscheidet die Zeilenreihenfolge.
        $clone->alias     = $this->uniqueAlias(
            'tl_news',
            $this->extractHeadlineValue((string) ($source->headline ?? '')),
        );

        $clone->save();
        return (int) $clone->id;
    }

    /**
     * tl_news.headline is the input-unit serialized payload `{unit, value}`.
     * Extract the human value for alias generation; fall back to the raw
     * string if it is not a serialized array (shouldn't happen, but defensive).
     */
    private function extractHeadlineValue(string $serialized): string
    {
        if ('' === $serialized) {
            return '';
        }
        $decoded = @unserialize($serialized, ['allowed_classes' => false]);
        if (\is_array($decoded) && isset($decoded['value'])) {
            return (string) $decoded['value'];
        }
        return $serialized;
    }

    /**
     * Resolve the Contao user id for the operator name. Mirrors
     * AbstractWriteCommand::resolveAuthorId so the cloned children get the
     * same author the editor would see when creating one manually via the
     * agent.
     */
    private function resolveAuthorId(string $operator): int
    {
        if ('' === $operator) {
            return 1;
        }
        if (!class_exists(UserModel::class)) {
            return 1;
        }
        $user = UserModel::findOneBy('username', $operator);
        return $user ? (int) $user->id : 1;
    }
}
