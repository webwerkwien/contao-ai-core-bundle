<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Language;

use Doctrine\DBAL\Connection;

/**
 * Why changelanguage offers no article to link to, said concretely (v1.11.1). Reads only.
 *
 * The package lists the articles of the page in the main language as targets — but it
 * finds that page through its PageFinder, which skips unpublished pages and roots (and
 * those outside their start/stop time). Pages kept unpublished on purpose (resource
 * pages, for example) had `language check` report their articles as a
 * gap, and `language link` refused with "the article's page has to be linked first" — the
 * page was linked. The gap cannot be closed, here or in the back end.
 *
 * What changelanguage checks, and so what this checks: the article's page is linked
 * (`languageMain`), the page it points to exists, is shown, and its root is shown. Not
 * the article's own page: the package does not ask for it.
 */
class LinkTargetDiagnosis
{
    /** @var array<int, array<string, mixed>|null> */
    private array $pages = [];

    /**
     * @param (\Closure(): int)|null $now the clock, for tests at the start/stop boundary
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Closure $now = null,
    ) {
    }

    /**
     * Unlinked articles split into those that can be linked and those that cannot, each
     * of the latter with page and reason — what `language:check` reports.
     *
     * @param list<int> $articleIds
     *
     * @return array{closable: list<int>, unlinkable: list<array{id: int, page: int, reason: string}>}
     */
    public function forArticles(array $articleIds): array
    {
        $split = ['closable' => [], 'unlinkable' => []];
        if ([] === $articleIds) {
            return $split;
        }

        $pids = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, pid FROM tl_article WHERE id IN (' . implode(',', array_map('intval', $articleIds)) . ')') as $row) {
            $pids[(int) $row['id']] = (int) $row['pid'];
        }

        $causes = [];
        foreach ($articleIds as $id) {
            $pid = $pids[$id] ?? 0;
            $causes[$pid] ??= $this->forArticlePage($pid) ?? '';
            if ('' === $causes[$pid]) {
                $split['closable'][] = $id;
            } else {
                $split['unlinkable'][] = ['id' => $id, 'page' => $pid, 'reason' => $causes[$pid]];
            }
        }

        return $split;
    }

    /**
     * Why an article on this page has no target, or null when the page and its main
     * counterpart give none of the known reasons.
     */
    public function forArticlePage(int $pageId): ?string
    {
        $this->pages = [];

        $page = $this->page($pageId);
        if (null === $page) {
            return null;
        }

        $main = (int) $page['languageMain'];
        if (0 === $main) {
            return \sprintf('Page %d is not linked to a page of the main language: link the page first (language link --table tl_page), then its articles.', $pageId);
        }

        $counterpart = $this->page($main);
        if (null === $counterpart) {
            return \sprintf('Page %d is linked to page %d, which does not exist (language check lists it as invalid).', $pageId, $main);
        }

        $root = $this->rootOf($counterpart);
        $rootWhy = null === $root ? null : $this->notShown($root);

        if (null !== ($why = $this->notShown($counterpart))) {
            // Both named when both are the reason: publishing the page alone would not do.
            return null === $rootWhy
                ? \sprintf('Page %d is linked to page %d of the main language, but page %d %s. changelanguage offers article targets only on published pages and roots — in the back end as well — so this article can be linked once page %d is published.', $pageId, $main, $main, $why, $main)
                : \sprintf('Page %d is linked to page %d of the main language, but page %d %s and its website root %d %s. changelanguage offers article targets only on published pages and roots — in the back end as well — so this article can be linked once both are published.', $pageId, $main, $main, $why, (int) $root['id'], $rootWhy);
        }

        if (null !== $root && null !== ($why = $rootWhy)) {
            return \sprintf('Page %d is linked to page %d of the main language, but its website root %d %s. changelanguage offers article targets only on published pages and roots — in the back end as well.', $pageId, $main, (int) $root['id'], $why);
        }

        return null;
    }

    /**
     * Why the page is not shown, as changelanguage's PageFinder decides it; null when it is.
     *
     * @param array<string, mixed> $page
     */
    private function notShown(array $page): ?string
    {
        // As PageFinder::addPublishingConditions(): start < now, stop > now + 60 (minutes floored)
        $now = (int) (floor((null === $this->now ? time() : ($this->now)()) / 60) * 60);

        return match (true) {
            '1' !== (string) $page['published']                                 => 'is unpublished',
            '' !== (string) $page['start'] && (int) $page['start'] >= $now      => 'is not shown yet (start ' . date('Y-m-d H:i', (int) $page['start']) . ')',
            '' !== (string) $page['stop'] && (int) $page['stop'] <= $now + 60   => 'is no longer shown (stop ' . date('Y-m-d H:i', (int) $page['stop']) . ')',
            default                                                            => null,
        };
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>|null
     */
    private function rootOf(array $page): ?array
    {
        for ($depth = 0; 'root' !== $page['type'] && $depth < 50; ++$depth) {
            $page = (int) $page['pid'] > 0 ? $this->page((int) $page['pid']) : null;
            if (null === $page) {
                return null;
            }
        }

        return $page;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page(int $id): ?array
    {
        if (!\array_key_exists($id, $this->pages)) {
            $row = $id > 0 ? $this->connection->fetchAssociative('SELECT id, pid, type, published, start, stop, languageMain FROM tl_page WHERE id = ?', [$id]) : false;
            $this->pages[$id] = false === $row ? null : $row;
        }

        return $this->pages[$id];
    }
}
