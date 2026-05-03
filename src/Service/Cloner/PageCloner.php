<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\ArticleModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Clones a tl_page plus its full editorial cascade. Two depth-axes:
 *
 *   1. **Nested content (always on):** each cloned tl_article also pulls
 *      every tl_content under it (pid+ptable=tl_article) AND recursively
 *      every nested content child (pid+ptable=tl_content) — handles
 *      accordion/colset/grouped layouts where the inner content elements
 *      reference the outer ones via ptable=tl_content.
 *
 *   2. **Subpage tree (opt-in via $options['recursive']):** when set, the
 *      page's full descendant tree (subpages, sub-subpages, …) is cloned
 *      under the new page id with regenerated aliases. Capped at depth 10
 *      and at MAX_TOTAL_PAGES total nodes to prevent runaway cascades.
 *
 * Aliases: every cloned page alias is regenerated from the new title plus
 * a short uniqueness suffix because `tl_page.alias` is unique-per-parent
 * and a verbatim copy under the same parent would collide. `tl_article`
 * alias likewise. `tl_content` has no alias.
 */
class PageCloner implements EntityClonerInterface
{
    private const ALLOWED_PAGE_MODIFICATIONS = ['title', 'pageTitle', 'description'];

    /**
     * Maximum descent depth for subpage-recursion. Stock Contao installs
     * rarely exceed 5-6 levels; 10 is a defensive cap that catches malformed
     * trees without truncating real-world structures.
     */
    private const MAX_RECURSIVE_DEPTH = 10;

    /**
     * Hard cap on total pages cloned in one run (root + descendants combined).
     * Prevents a "clone the entire site" prompt from running for minutes and
     * blowing past the operator's expectations.
     */
    private const MAX_TOTAL_PAGES = 50;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly VersionManager $versionManager,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_page' === $table;
    }

    public function clone(int $sourceId, array $modifications, string $operator, array $options = []): array
    {
        $this->framework->initialize();

        $source = PageModel::findById($sourceId);
        if (null === $source) {
            throw new \RuntimeException(\sprintf('Page %d nicht gefunden.', $sourceId));
        }

        $filteredMods = [];
        foreach ($modifications as $key => $value) {
            if (\in_array($key, self::ALLOWED_PAGE_MODIFICATIONS, true)) {
                $filteredMods[$key] = $value;
            }
        }

        $authorId  = $this->resolveAuthorId($operator);
        $recursive = (bool) ($options['recursive'] ?? false);

        return $this->connection->transactional(function () use ($source, $filteredMods, $operator, $authorId, $recursive): array {
            $stats = ['articles' => 0, 'contents' => 0, 'subpages' => 0, 'capped' => false];

            $newRootId = $this->doClone(
                $source,
                $filteredMods,
                $operator,
                $authorId,
                $recursive,
                0,
                $stats,
                null,
            );

            return [
                'id'             => $newRootId,
                'table'          => 'tl_page',
                'count'          => $stats['articles'], // legacy field — kept for symmetry mit anderen Cloner-Outputs
                'article_count'  => $stats['articles'],
                'content_count'  => $stats['contents'],
                'subpage_count'  => $stats['subpages'],
                'capped'         => $stats['capped'],
            ];
        });
    }

    /**
     * @param array<string, scalar|null> $modifications  Apply only to the ROOT clone (the user's
     *   explicit target). Subpage clones during recursive descent get an empty modifications
     *   array — they preserve whatever the source-subpage already had, just with the new pid.
     * @param array{articles:int, contents:int, subpages:int, capped:bool} $stats  Mutable stats counter.
     */
    private function doClone(
        PageModel $source,
        array $modifications,
        string $operator,
        int $authorId,
        bool $recursive,
        int $depth,
        array &$stats,
        ?int $parentNewId,
    ): int {
        // Subpage-Tree-Caps: depth + total-nodes. Wenn überschritten, nicht klonen
        // und das `capped`-Flag setzen — der Aufrufer kann das in den Result-Payload
        // hochreichen damit der Operator es erfährt.
        if ($depth > self::MAX_RECURSIVE_DEPTH) {
            $stats['capped'] = true;
            return 0;
        }
        $totalSoFar = 1 /*root*/ + $stats['subpages'];
        if ($totalSoFar > self::MAX_TOTAL_PAGES) {
            $stats['capped'] = true;
            return 0;
        }

        $newPageId = $this->clonePageRow($source, $modifications, $parentNewId);
        $this->versionManager->createVersion('tl_page', $newPageId, $operator);

        // Articles + Content
        $articles = ArticleModel::findBy('pid', (int) $source->id);
        if (null !== $articles) {
            foreach ($articles as $sourceArticle) {
                $newArticleId = $this->cloneArticleRow($sourceArticle, $newPageId, $authorId);
                $this->versionManager->createVersion('tl_article', $newArticleId, $operator);
                ++$stats['articles'];

                // Direkte Children unter Article (ptable=tl_article)
                $directContents = ContentModel::findBy(
                    ['pid=?', 'ptable=?'],
                    [(int) $sourceArticle->id, 'tl_article']
                );
                if (null !== $directContents) {
                    foreach ($directContents as $sourceContent) {
                        $newContentId = $this->cloneContentRow($sourceContent, $newArticleId);
                        $this->versionManager->createVersion('tl_content', $newContentId, $operator);
                        ++$stats['contents'];
                        // Verschachtelte Content-Children (ptable=tl_content)
                        $this->cloneNestedContent((int) $sourceContent->id, $newContentId, $operator, $stats);
                    }
                }
            }
        }

        // Subpage-Tree-Recursion (opt-in)
        if ($recursive) {
            $subpages = PageModel::findBy('pid', (int) $source->id);
            if (null !== $subpages) {
                foreach ($subpages as $subpage) {
                    ++$stats['subpages'];
                    $this->doClone(
                        $subpage,
                        [], // Modifications nur für Root anwenden
                        $operator,
                        $authorId,
                        true,
                        $depth + 1,
                        $stats,
                        $newPageId,
                    );
                }
            }
        }

        return $newPageId;
    }

    /**
     * Recursively clone tl_content children attached via ptable=tl_content
     * (accordion, colset, grouped layouts). Each level: WHERE pid=oldId
     * AND ptable=tl_content → clone with pid=newId AND ptable=tl_content.
     *
     * @param array{articles:int, contents:int, subpages:int, capped:bool} $stats
     */
    private function cloneNestedContent(int $oldParentId, int $newParentId, string $operator, array &$stats): void
    {
        $children = ContentModel::findBy(
            ['pid=?', 'ptable=?'],
            [$oldParentId, 'tl_content']
        );
        if (null === $children) {
            return;
        }
        foreach ($children as $sourceChild) {
            $newChildId = $this->cloneContentRow($sourceChild, $newParentId);
            $this->versionManager->createVersion('tl_content', $newChildId, $operator);
            ++$stats['contents'];
            $this->cloneNestedContent((int) $sourceChild->id, $newChildId, $operator, $stats);
        }
    }

    /**
     * @param array<string, scalar|null> $modifications  Empty for subpage-recursion descent;
     *   only the operator-supplied root call gets the actual override values applied.
     */
    private function clonePageRow(PageModel $source, array $modifications, ?int $parentNewId): int
    {
        $clone = new PageModel();
        foreach ($source->row() as $key => $value) {
            if ('id' === $key) {
                continue;
            }
            $clone->$key = $value;
        }
        $clone->tstamp = time();
        if (null !== $parentNewId) {
            $clone->pid = $parentNewId;
        }
        foreach ($modifications as $key => $value) {
            $clone->$key = (string) $value;
        }
        if (!isset($modifications['title'])) {
            $clone->title = ((string) ($source->title ?? '')) . ' (Kopie)';
        }
        // Alias muss eindeutig sein. `tl_page.alias` hat in stock Contao eine
        // unique-per-parent-Constraint — wir hängen `-kopie-<short-rand>` an,
        // damit auch beim recursive-clone (mehrere Pages mit gleicher Source-
        // alias unter neuem parent) keine Collision entsteht.
        $clone->alias = StringUtil::generateAlias((string) ($clone->title ?? ''))
            . '-kopie-' . substr(md5(uniqid('', true)), 0, 4);

        $clone->save();
        return (int) $clone->id;
    }

    private function cloneArticleRow(ArticleModel $source, int $newPageId, int $authorId): int
    {
        $clone = new ArticleModel();
        foreach ($source->row() as $key => $value) {
            if ('id' === $key) {
                continue;
            }
            $clone->$key = $value;
        }
        $clone->tstamp    = time();
        $clone->pid       = $newPageId;
        $clone->author    = $authorId;
        $clone->published = '0';
        $clone->alias     = StringUtil::generateAlias(
            (string) ($source->title ?? '') ?: ('article-kopie-' . time())
        ) . '-' . $newPageId;

        $clone->save();
        return (int) $clone->id;
    }

    /**
     * Klont einen tl_content-Eintrag mit dem angegebenen neuen pid. Die `ptable`
     * wird unverändert von row() übernommen — aufrufende Stelle entscheidet
     * implizit über den Container-Typ (tl_article vs. tl_content) durch den
     * passenden $newPid.
     */
    private function cloneContentRow(ContentModel $source, int $newPid): int
    {
        $clone = new ContentModel();
        foreach ($source->row() as $key => $value) {
            if ('id' === $key) {
                continue;
            }
            $clone->$key = $value;
        }
        $clone->tstamp    = time();
        $clone->pid       = $newPid;
        $clone->invisible = '1'; // Klon-Inhalte unsichtbar bis Operator-Review

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
