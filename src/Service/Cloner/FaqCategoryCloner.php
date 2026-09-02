<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FaqCategoryModel;
use Contao\FaqModel;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Clones a tl_faq_category container plus all its tl_faq children inside
 * a single DB transaction. Same cascade pattern as NewsArchiveCloner /
 * CalendarCloner.
 *
 * Note: tl_faq has no `published` column in stock Contao 5 — instead it
 * has `published='1'`/`''` like news. tl_faq.author exists, so the
 * cloned children get the operator stamped as author. Cloned FAQs land
 * unpublished (operator review before going live).
 */
class FaqCategoryCloner implements EntityClonerInterface
{
    use CopiesSourceRows;
    use GeneratesUniqueAlias;
    use FiltersModifications;

    private const ALLOWED_CATEGORY_MODIFICATIONS = ['title', 'headline'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_faq_category' === $table;
    }

    public function clone(int $sourceId, array $modifications, string $operator, array $options = []): array
    {
        // FAQ-Categories haben keine container-of-container-Hierarchie — `recursive`
        // wird ignoriert. Signature-Kompat zur Interface-Erweiterung.
        $this->framework->initialize();

        $source = FaqCategoryModel::findById($sourceId);
        if (null === $source) {
            throw new \RuntimeException(\sprintf('FAQ-Kategorie %d nicht gefunden.', $sourceId));
        }

        ['accepted' => $filteredMods, 'ignored' => $ignoredMods] = $this->partitionModifications(
            $modifications,
            self::ALLOWED_CATEGORY_MODIFICATIONS,
        );

        $authorId = $this->resolveAuthorId($operator);

        return $this->connection->transactional(function () use ($source, $sourceId, $filteredMods, $ignoredMods, $operator, $authorId): array {
            $newId = $this->cloneCategoryRow($source, $filteredMods);
            $this->versionManager->createVersion('tl_faq_category', $newId, $operator);

            $count = 0;
            $children = FaqModel::findBy('pid', $sourceId);
            if (null !== $children) {
                foreach ($children as $child) {
                    $newChildId = $this->cloneFaqRow($child, $newId, $authorId);
                    $this->versionManager->createVersion('tl_faq', $newChildId, $operator);
                    ++$count;
                }
            }

            return [
                'id'    => $newId,
                'table' => 'tl_faq_category',
                'count' => $count,
                // Overrides this cloner refused. Empty on a clean call; never omitted.
                'ignored_modifications' => $ignoredMods,
            ];
        });
    }

    /**
     * @param array<string, scalar|null> $modifications
     */
    private function cloneCategoryRow(FaqCategoryModel $source, array $modifications): int
    {
        $clone = new FaqCategoryModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_faq_category', (int) $source->id));
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

    private function cloneFaqRow(FaqModel $source, int $newCategoryId, int $authorId): int
    {
        $clone = new FaqModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_faq', (int) $source->id));
        $clone->tstamp    = time();
        $clone->pid       = $newCategoryId;
        $clone->author    = $authorId;
        $clone->published = '0';
        // 🔴 H-6 — siehe GeneratesUniqueAlias. `tl_faq.alias` ist
        // `unique=>true, doNotCopy=>true`.
        $clone->alias     = $this->uniqueAlias('tl_faq', (string) ($source->question ?? ''));

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
