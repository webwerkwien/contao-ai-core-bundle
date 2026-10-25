<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Clones a tl_calendar container plus all its tl_calendar_events children
 * inside a single DB transaction. Mirror of NewsArchiveCloner — same
 * cascade-+-version-snapshot-pattern, just for calendar entities.
 */
class CalendarCloner implements EntityClonerInterface
{
    use CopiesSourceRows;
    use GeneratesUniqueAlias;
    use ClonesContentSubtree;
    use FiltersModifications;

    private const ALLOWED_CALENDAR_MODIFICATIONS = ['title'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
        private readonly ?DcaCallbacks $dcaCallbacks = null,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_calendar' === $table;
    }

    public function clone(int $sourceId, array $modifications, string $operator, array $options = []): array
    {
        // Calendar has no container-of-container hierarchy — `recursive`
        // is ignored. Signature compatibility with the interface extension.
        $this->framework->initialize();

        $source = CalendarModel::findById($sourceId);
        if (null === $source) {
            throw new \InvalidArgumentException(\sprintf('Calendar %d nicht gefunden.', $sourceId));
        }

        ['accepted' => $filteredMods, 'ignored' => $ignoredMods] = $this->partitionModifications(
            $modifications,
            self::ALLOWED_CALENDAR_MODIFICATIONS,
        );

        $authorId = $this->resolveAuthorId($operator);

        return $this->connection->transactional(function () use ($source, $sourceId, $filteredMods, $ignoredMods, $operator, $authorId): array {
            $newId = $this->cloneCalendarRow($source, $filteredMods);
            $this->versionManager->createInitialVersion('tl_calendar', $newId, $operator);

            $count    = 0;
            $contents = 0;
            $children = CalendarEventsModel::findBy('pid', $sourceId);
            if (null !== $children) {
                foreach ($children as $child) {
                    $newChildId = $this->cloneEventRow($child, $newId, $authorId);
                    $this->versionManager->createInitialVersion('tl_calendar_events', $newChildId, $operator);
                    ++$count;

                    // 🔴 Content elements under the child used to never be copied along.
                    // The delete path cascades them (RecordCascadeCollector: "tl_content
                    // hangs under articles, news and events"), the clone left them behind
                    // and still reported success.
                    $contents += $this->cloneContentSubtree((int) $child->id, $newChildId, 'tl_calendar_events', $operator);
                    $this->dcaCallbacks?->copy('tl_calendar_events', $newChildId, (int) $child->id);
                }
            }

            // The root last, as DC_Table::copy() does (v1.4.0).
            $this->dcaCallbacks?->copy('tl_calendar', $newId, $sourceId);

            return [
                'id'    => $newId,
                'table' => 'tl_calendar',
                'count' => $count,
                // Reported separately, so that an empty count stands out
                'contents' => $contents,
                // Overrides this cloner refused. Empty on a clean call; never omitted.
                'ignored_modifications' => $ignoredMods,
            ];
        });
    }

    /**
     * @param array<string, scalar|null> $modifications
     */
    private function cloneCalendarRow(CalendarModel $source, array $modifications): int
    {
        $clone = new CalendarModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_calendar', (int) $source->id));
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

    private function cloneEventRow(CalendarEventsModel $source, int $newCalendarId, int $authorId): int
    {
        $clone = new CalendarEventsModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_calendar_events', (int) $source->id));
        $clone->tstamp    = time();
        $clone->pid       = $newCalendarId;
        $clone->author    = $authorId;
        $clone->published = '0';
        // 🔴 See GeneratesUniqueAlias. `tl_calendar_events.alias` is
        // `unique=>true, doNotCopy=>true`.
        $clone->alias     = $this->uniqueAlias('tl_calendar_events', (string) ($source->title ?? ''));

        $clone->save();
        return (int) $clone->id;
    }

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
