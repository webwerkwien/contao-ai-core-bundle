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
    use CopiesSourceRows;
    use ClonesContentSubtree;
    use FiltersModifications;

    /**
     * `published` and `hide` were added on 2026-08-29.
     *
     * The line this list draws: **an override is accepted when it controls
     * whether and where the clone becomes visible.** That is the core of
     * cloning — copy it, but do not surface it yet. The cloned content elements
     * are already forced to `invisible = '1'` for the same reason; the page
     * itself had no counterpart, and saying otherwise was discarded in silence.
     *
     * `published` covers "not live". `hide` covers the case after that: a clone
     * that will be published but must stay out of the navigation — a test
     * variant, a landing page, anything reachable only by its URL. Without it
     * that needs a second write, and the page sits in the menu in between.
     *
     * `protected` deliberately stays out. It is access control, not visibility:
     * a `protected: ""` on the clone of a protected page would expose its
     * content, which is the kind of mistake this list exists to prevent.
     */
    private const ALLOWED_PAGE_MODIFICATIONS = ['title', 'pageTitle', 'description', 'published', 'hide'];

    /**
     * Whitelisted overrides that address a tinyint flag column and therefore
     * must not be written verbatim — see FiltersModifications::normaliseFlag().
     * Every entry here must also appear in ALLOWED_PAGE_MODIFICATIONS.
     */
    private const FLAG_PAGE_MODIFICATIONS = ['published', 'hide'];

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

        ['accepted' => $filteredMods, 'ignored' => $ignoredMods] = $this->partitionModifications(
            $modifications,
            self::ALLOWED_PAGE_MODIFICATIONS,
        );

        foreach (self::FLAG_PAGE_MODIFICATIONS as $flag) {
            if (\array_key_exists($flag, $filteredMods)) {
                $filteredMods[$flag] = $this->normaliseFlag($filteredMods[$flag]);
            }
        }

        $authorId  = $this->resolveAuthorId($operator);
        $recursive = (bool) ($options['recursive'] ?? false);

        return $this->connection->transactional(function () use ($source, $filteredMods, $ignoredMods, $operator, $authorId, $recursive): array {
            $stats = ['articles' => 0, 'contents' => 0, 'subpages' => 0, 'subpages_skipped' => 0, 'capped' => false];

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
                // M-1: getrennt ausgewiesen. `capped: true` sagte bisher nur
                // DASS etwas übersprungen wurde, nicht wie viel.
                'subpages_skipped' => $stats['subpages_skipped'],
                'capped'         => $stats['capped'],
                // Overrides this cloner refused. Empty on a clean call; never omitted,
                // so a caller can check the key instead of guessing.
                'ignored_modifications' => $ignoredMods,
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
            ++$stats['subpages_skipped'];
            return 0;
        }
        // 🔴 M-1 (Audit 2026-09-02): `$stats['subpages']` zählte bis dahin
        // VERSUCHE, nicht Erzeugungen — der Aufrufer erhöhte vor dem Aufruf,
        // und ein durch die Grenze abgewiesener Aufruf lieferte 0, ohne den
        // Zähler zurückzunehmen. Die Antwort meldete damit mehr geklonte
        // Unterseiten, als es gab.
        //
        // Die Grenze rechnete mit demselben Zähler, deshalb ändert sich hier
        // `>` zu `>=`: vorher enthielt `subpages` die gerade versuchte Seite
        // bereits, jetzt nicht mehr. Die Obergrenze bleibt dieselbe — nach 49
        // Unterseiten plus Root sind 50 erreicht und die nächste wird
        // abgewiesen.
        $totalSoFar = 1 /*root*/ + $stats['subpages'];
        if ($totalSoFar >= self::MAX_TOTAL_PAGES) {
            $stats['capped'] = true;
            ++$stats['subpages_skipped'];
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
                        // Verschachtelte Content-Children (ptable=tl_content) —
                        // seit H-5 in ClonesContentSubtree, geteilt mit den
                        // Archiv- und Kalender-Klonern.
                        $stats['contents'] += $this->cloneContentSubtree(
                            (int) $sourceContent->id,
                            $newContentId,
                            'tl_content',
                            $operator,
                        );
                    }
                }
            }
        }

        // Subpage-Tree-Recursion (opt-in)
        if ($recursive) {
            $subpages = PageModel::findBy('pid', (int) $source->id);
            if (null !== $subpages) {
                foreach ($subpages as $subpage) {
                    // Erst zählen, wenn wirklich eine Seite entstanden ist.
                    // doClone() liefert 0, wenn Tiefe oder Gesamtzahl greifen.
                    $clonedId = $this->doClone(
                        $subpage,
                        [], // Modifications nur für Root anwenden
                        $operator,
                        $authorId,
                        true,
                        $depth + 1,
                        $stats,
                        $newPageId,
                    );

                    if ($clonedId > 0) {
                        ++$stats['subpages'];
                    }
                }
            }
        }

        return $newPageId;
    }


    /**
     * @param array<string, scalar|null> $modifications  Empty for subpage-recursion descent;
     *   only the operator-supplied root call gets the actual override values applied.
     */
    private function clonePageRow(PageModel $source, array $modifications, ?int $parentNewId): int
    {
        $clone = new PageModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_page', (int) $source->id));
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
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_article', (int) $source->id));
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
