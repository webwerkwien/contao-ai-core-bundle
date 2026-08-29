<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Clones a tl_calendar container plus all its tl_calendar_events children
 * inside a single DB transaction. Mirror of NewsArchiveCloner — same
 * cascade-+-version-snapshot-pattern, just for calendar entities.
 */
class CalendarCloner implements EntityClonerInterface
{
    use CopiesSourceRows;
    use FiltersModifications;

    private const ALLOWED_CALENDAR_MODIFICATIONS = ['title'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_calendar' === $table;
    }

    public function clone(int $sourceId, array $modifications, string $operator, array $options = []): array
    {
        // Calendar hat keine container-of-container-Hierarchie — `recursive`
        // wird ignoriert. Signature-Kompat zur Interface-Erweiterung.
        $this->framework->initialize();

        $source = CalendarModel::findById($sourceId);
        if (null === $source) {
            throw new \RuntimeException(\sprintf('Calendar %d nicht gefunden.', $sourceId));
        }

        ['accepted' => $filteredMods, 'ignored' => $ignoredMods] = $this->partitionModifications(
            $modifications,
            self::ALLOWED_CALENDAR_MODIFICATIONS,
        );

        $authorId = $this->resolveAuthorId($operator);

        return $this->connection->transactional(function () use ($source, $sourceId, $filteredMods, $ignoredMods, $operator, $authorId): array {
            $newId = $this->cloneCalendarRow($source, $filteredMods);
            $this->versionManager->createVersion('tl_calendar', $newId, $operator);

            $count = 0;
            $children = CalendarEventsModel::findBy('pid', $sourceId);
            if (null !== $children) {
                foreach ($children as $child) {
                    $newChildId = $this->cloneEventRow($child, $newId, $authorId);
                    $this->versionManager->createVersion('tl_calendar_events', $newChildId, $operator);
                    ++$count;
                }
            }

            return [
                'id'    => $newId,
                'table' => 'tl_calendar',
                'count' => $count,
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
        $clone->alias     = StringUtil::generateAlias(
            (string) ($source->title ?? '') ?: ('kopie-' . time())
        );

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
