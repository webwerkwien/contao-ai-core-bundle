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
 * Clones a single tl_page plus its full editorial cascade — every
 * tl_article on the page, plus every tl_content element on each of
 * those articles. NOT recursive over the page tree (subpages are NOT
 * cloned); add a `recursive` flag in a follow-up sub-phase if needed.
 *
 * Aliases: tl_page.alias is regenerated from the new title because the
 * source alias is unique per parent and would clash if we landed under
 * the same parent. tl_article.alias likewise. tl_content has no alias.
 *
 * tl_content tree depth: stock Contao supports nested content elements
 * via pid+ptable=tl_content (e.g. accordion children). The first
 * cascade pass handles ptable=tl_article children only — nested
 * tl_content children are NOT recursively cloned in this MVP. Most
 * Contao demo content uses flat tl_article → tl_content layouts so
 * this covers the typical case.
 */
class PageCloner implements EntityClonerInterface
{
    private const ALLOWED_PAGE_MODIFICATIONS = ['title', 'pageTitle', 'description'];

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

    public function clone(int $sourceId, array $modifications, string $operator): array
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

        $authorId = $this->resolveAuthorId($operator);

        return $this->connection->transactional(function () use ($source, $sourceId, $filteredMods, $operator, $authorId): array {
            $newPageId = $this->clonePageRow($source, $filteredMods);
            $this->versionManager->createVersion('tl_page', $newPageId, $operator);

            $articleCount = 0;
            $contentCount = 0;

            $articles = ArticleModel::findBy('pid', $sourceId);
            if (null !== $articles) {
                foreach ($articles as $sourceArticle) {
                    $newArticleId = $this->cloneArticleRow($sourceArticle, $newPageId, $authorId);
                    $this->versionManager->createVersion('tl_article', $newArticleId, $operator);
                    ++$articleCount;

                    $contents = ContentModel::findBy(
                        ['pid=?', 'ptable=?'],
                        [(int) $sourceArticle->id, 'tl_article']
                    );
                    if (null !== $contents) {
                        foreach ($contents as $sourceContent) {
                            $newContentId = $this->cloneContentRow($sourceContent, $newArticleId);
                            $this->versionManager->createVersion('tl_content', $newContentId, $operator);
                            ++$contentCount;
                        }
                    }
                }
            }

            return [
                'id'             => $newPageId,
                'table'          => 'tl_page',
                'count'          => $articleCount,
                'article_count'  => $articleCount,
                'content_count'  => $contentCount,
            ];
        });
    }

    /**
     * @param array<string, scalar|null> $modifications
     */
    private function clonePageRow(PageModel $source, array $modifications): int
    {
        $clone = new PageModel();
        foreach ($source->row() as $key => $value) {
            if ('id' === $key) {
                continue;
            }
            $clone->$key = $value;
        }
        $clone->tstamp = time();

        foreach ($modifications as $key => $value) {
            $clone->$key = (string) $value;
        }
        if (!isset($modifications['title'])) {
            $clone->title = ((string) ($source->title ?? '')) . ' (Kopie)';
        }
        // Alias muss eindeutig sein — zwingend regenerieren, sonst schreibt Contao
        // beim ersten Backend-Save eine Kollision-Suffix dran und wir wissen nicht
        // mit welchem Alias der Klon dann tatsächlich liegt.
        $clone->alias = StringUtil::generateAlias((string) ($clone->title ?? '')) . '-kopie';

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

    private function cloneContentRow(ContentModel $source, int $newArticleId): int
    {
        $clone = new ContentModel();
        foreach ($source->row() as $key => $value) {
            if ('id' === $key) {
                continue;
            }
            $clone->$key = $value;
        }
        $clone->tstamp = time();
        $clone->pid    = $newArticleId;
        // ptable bleibt 'tl_article' — wird über row() schon mitkopiert
        // und der neue pid hängt in derselben Tabelle ein.
        $clone->invisible = '1'; // Klon-Inhalte unsichtbar bis Operator review

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
