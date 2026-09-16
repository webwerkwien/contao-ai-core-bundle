<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\ArticleModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\Page\PageUrlGuard;
use Webwerkwien\ContaoAiCoreBundle\Service\Sorting;
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
     * Accepted in addition when the source is a root (v0.15.0).
     *
     * Cloning a site into another language is the main reason to clone a root, and
     * until v0.14.0 it could only go through the state Contao forbids: the clone kept
     * the source's domain and prefix, and `language`/`urlPrefix` were ignored. The
     * result is checked by PageUrlGuard before the transaction commits.
     */
    private const ROOT_PAGE_MODIFICATIONS = ['language', 'urlPrefix', 'urlSuffix', 'fallback', 'dns'];

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
        private readonly PageUrlGuard $pageUrlGuard,
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

        $isRoot = 'root' === $source->type;

        ['accepted' => $filteredMods, 'ignored' => $ignoredMods] = $this->partitionModifications(
            $modifications,
            $isRoot ? [...self::ALLOWED_PAGE_MODIFICATIONS, ...self::ROOT_PAGE_MODIFICATIONS] : self::ALLOWED_PAGE_MODIFICATIONS,
        );

        foreach ([...self::FLAG_PAGE_MODIFICATIONS, 'fallback'] as $flag) {
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

            // Before the commit: a clone may not produce what Contao's back end
            // refuses (v0.15.0). A root cloned onto the same domain and prefix is
            // rolled back — pass `urlPrefix`, `dns` or both in the modifications.
            try {
                $this->pageUrlGuard->assertRootUnique($newRootId);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException($e->getMessage() . ' When cloning a root, give it its own "urlPrefix" or "dns" in --modifications.', 0, $e);
            }
            $this->pageUrlGuard->assertAliases([$newRootId]);
            $this->pageUrlGuard->assertTree($newRootId);

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
     * @param array{articles:int, contents:int, subpages:int, subpages_skipped:int, capped:bool} $stats  Mutable stats counter.
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
        $this->versionManager->createInitialVersion('tl_page', $newPageId, $operator);

        // Articles + Content
        $articles = ArticleModel::findBy('pid', (int) $source->id);
        if (null !== $articles) {
            foreach ($articles as $sourceArticle) {
                $newArticleId = $this->cloneArticleRow($sourceArticle, $newPageId, $authorId);
                $this->versionManager->createInitialVersion('tl_article', $newArticleId, $operator);
                ++$stats['articles'];

                // Direkte Children unter Article (ptable=tl_article)
                $directContents = ContentModel::findBy(
                    ['pid=?', 'ptable=?'],
                    [(int) $sourceArticle->id, 'tl_article']
                );
                if (null !== $directContents) {
                    foreach ($directContents as $sourceContent) {
                        $newContentId = $this->cloneContentRow($sourceContent, $newArticleId);
                        $this->versionManager->createInitialVersion('tl_content', $newContentId, $operator);
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

        // The cloned root goes behind its last sibling, like every created record
        // (v0.15.0; it kept sorting 0). Subpages keep their source sorting, which
        // preserves their order under the new parent.
        if (null === $parentNewId) {
            $max = $this->connection->fetchOne('SELECT MAX(sorting) FROM tl_page WHERE pid = ?', [(int) $clone->pid]);
            $clone->sorting = Sorting::after(null === $max || false === $max ? null : (int) $max);
        }

        // The alias is Contao's, as in a back-end copy: `tl_page.alias` carries
        // `doNotCopy`, and PageUrlListener::generateAlias() makes one from the
        // title that is unique for the page's URL. Until v0.14.0 this appended
        // `-kopie-<random>` to a slug of the already suffixed title, which turned
        // `index` into `startseite-kopie-kopie-9224`.
        $clone->alias = '';
        $clone->save();
        $newId = (int) $clone->id;

        // generateAlias() calls PageModel::findWithDetails(), whose loadDetails()
        // detaches this very instance from the registry and forbids saving it ("The
        // model instance has been detached" — measured on c5 on 2026-09-16). So the
        // alias is saved through a fresh instance: once detached, findByPk() loads
        // the record anew and registers it. v0.15.0 wrote it through the connection,
        // which worked but left the model layer; Michael questioned it (v0.15.1).
        // The version snapshot is taken after this method returns and carries it.
        $alias = $this->pageUrlGuard->generateAlias($newId);
        $fresh = PageModel::findByPk($newId);

        if (null === $fresh) {
            throw new \RuntimeException(\sprintf('Cloned page %d vanished before its alias could be saved.', $newId));
        }

        $fresh->alias = $alias;
        $fresh->save();

        return $newId;
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
