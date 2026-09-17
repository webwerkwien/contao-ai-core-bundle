<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Page;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\DC_Table;
use Contao\PageModel;
use Contao\System;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Keeps page writes to the URL rules Contao's back end enforces.
 *
 * Until v0.14.0 the CLI wrote what `PageUrlListener` refuses in the back end: two
 * roots with the same domain and prefix, two pages with the same URL. Measured on
 * c5 on 2026-09-16, see PageUrlGuardTest.
 *
 * Use it inside the transaction of the write, after the write: the alias check reads
 * the stored record, and a refusal must roll the write back.
 */
final class PageUrlGuard
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly ?PageRegistry $pageRegistry = null,
    ) {
    }

    /**
     * Pages whose URL may collide with this one — the hint Contao's back end shows.
     *
     * Not a refusal: the back end saves a second page with alias `index` under the same
     * root and only shows "the following pages have a similar alias that may conflict"
     * (`PageRoutingListener::generateRouteConflicts()`). Until v0.20.0 the bundle saved
     * the same page silently (Nr. 48 of the ConpAI 1.0 acceptance test, 2026-09-17).
     *
     * @return list<array{id: int, title: string, alias: string, path: string}>
     */
    public function routeConflicts(int $pageId): array
    {
        if (null === $this->pageRegistry) {
            return [];
        }

        // Runs after the write has committed: a hint must never turn a saved page into
        // an error answer, which an agent would retry (review 2026-09-17). Exceptions
        // only — an \Error is a programming mistake and must stay visible, since no unit
        // test reaches this Contao glue.
        try {
            $this->framework->initialize();

            $pages   = $this->framework->getAdapter(PageModel::class);
            $current = $pages->findWithDetails($pageId);

            if (!$current instanceof PageModel || !$this->pageRegistry->isRoutable($current)) {
                return [];
            }

            $similar = $pages->findSimilarByAlias($current);

            if (null === $similar) {
                return [];
            }

            $candidates = [];

            foreach ($similar as $page) {
                $page->loadDetails();
                $candidates[] = $this->routeFacts($page);
            }

            return self::selectRouteConflicts($this->routeFacts($current), $candidates);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Contao's rule, on plain data: same domain, routable, same static URL.
     *
     * @param array{id: int, title: string, alias: string, domain: string, url: string, path: string, routable: bool}       $current
     * @param list<array{id: int, title: string, alias: string, domain: string, url: string, path: string, routable: bool}> $candidates
     *
     * @return list<array{id: int, title: string, alias: string, path: string}>
     */
    public static function selectRouteConflicts(array $current, array $candidates): array
    {
        if (!$current['routable']) {
            return [];
        }

        $conflicts = [];

        foreach ($candidates as $page) {
            if ($page['id'] === $current['id'] || !$page['routable']) {
                continue;
            }

            if ($page['domain'] !== $current['domain'] || $page['url'] !== $current['url']) {
                continue;
            }

            $conflicts[] = ['id' => $page['id'], 'title' => $page['title'], 'alias' => $page['alias'], 'path' => $page['path']];
        }

        return $conflicts;
    }

    /**
     * @return array{id: int, title: string, alias: string, domain: string, url: string, path: string, routable: bool}
     */
    private function routeFacts(PageModel $page): array
    {
        \assert(null !== $this->pageRegistry);

        $routable = $this->pageRegistry->isRoutable($page);
        $url      = '';
        $path     = '';

        if ($routable) {
            $route = $this->pageRegistry->getRoute($page);
            $url   = $route->compile()->getStaticPrefix() . $route->getUrlSuffix();
            $path  = $route->getPath();
        }

        return [
            'id'       => (int) $page->id,
            'title'    => (string) $page->title,
            'alias'    => (string) $page->alias,
            'domain'   => (string) $page->domain,
            'url'      => $url,
            'path'     => $path,
            'routable' => $routable,
        ];
    }

    /**
     * Run a write and its checks in one transaction.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function transactional(callable $write): mixed
    {
        return $this->connection->transactional(static fn () => $write());
    }

    /**
     * No other root may share this root's domain and URL prefix.
     *
     * Contao's own query from `PageUrlListener::validateUrlPrefix()`, identical in
     * 5.3, 5.7 and 6.0. An empty prefix is a prefix like any other. A page that is
     * not a root is not counted.
     *
     * @throws \InvalidArgumentException
     */
    public function assertRootUnique(int $pageId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, type, dns, urlPrefix FROM tl_page WHERE id = ?',
            [$pageId],
        );

        if (false === $row || 'root' !== ($row['type'] ?? null)) {
            return;
        }

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_page WHERE urlPrefix = :urlPrefix AND dns = :dns AND id != :rootId AND type = 'root'",
            [
                'urlPrefix' => (string) ($row['urlPrefix'] ?? ''),
                'dns'       => (string) ($row['dns'] ?? ''),
                'rootId'    => $pageId,
            ],
        );

        if ($count > 0) {
            throw new \InvalidArgumentException(\sprintf(
                'Another root page already uses domain "%s" with URL prefix "%s". Nothing was written. '
                . 'Domain and prefix decide which root — and so which language, 404 page and sitemap — '
                . 'answers a request; Contao refuses the same in the back end (PageUrlListener).',
                (string) ($row['dns'] ?? ''),
                (string) ($row['urlPrefix'] ?? ''),
            ));
        }
    }

    /**
     * Every given page's URL must be unique — Contao's own check, called directly.
     *
     * @param list<int> $pageIds
     *
     * @throws \InvalidArgumentException naming the page whose URL is taken
     */
    public function assertAliases(array $pageIds): void
    {
        foreach ($pageIds as $pageId) {
            $alias = (string) $this->connection->fetchOne('SELECT alias FROM tl_page WHERE id = ?', [$pageId]);

            if ('' === $alias) {
                continue;
            }

            try {
                $this->callAliasCallback($pageId, $alias);
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException(\sprintf(
                    'Page %d: %s Nothing was written.',
                    $pageId,
                    html_entity_decode(strip_tags($e->getMessage()), ENT_QUOTES | ENT_HTML5),
                ), 0, $e);
            }
        }
    }

    /**
     * Check a whole tree — after a root's domain, prefix or suffix changed, every URL
     * below it changed with it.
     *
     * @throws \InvalidArgumentException
     */
    public function assertTree(int $rootId): void
    {
        $this->assertAliases($this->descendantIds($rootId));
    }

    /**
     * The alias Contao would give this page: generated from its title and made unique,
     * as a back-end copy gets one (tl_page.alias carries `doNotCopy`).
     */
    public function generateAlias(int $pageId): string
    {
        return $this->callAliasCallback($pageId, '');
    }

    /**
     * Contao's `PageUrlListener::generateAlias()` among an alias field's save callbacks.
     *
     * @param array<mixed> $callbacks
     *
     * @return array{0: string, 1: string}|null
     */
    public static function findAliasCallback(array $callbacks): ?array
    {
        foreach ($callbacks as $callback) {
            if (\is_array($callback) && \is_string($callback[0] ?? null) && 'generateAlias' === ($callback[1] ?? null)) {
                return [$callback[0], 'generateAlias'];
            }
        }

        return null;
    }

    private function callAliasCallback(int $pageId, string $value): string
    {
        $this->framework->initialize();

        if (!isset($GLOBALS['TL_DCA']['tl_page']['fields'])) {
            Controller::loadDataContainer('tl_page');
        }

        $callback = self::findAliasCallback($GLOBALS['TL_DCA']['tl_page']['fields']['alias']['save_callback'] ?? []);

        if (null === $callback) {
            throw new \LogicException('Contao\'s PageUrlListener::generateAlias() is not registered on tl_page.alias — URL rules cannot be checked.');
        }

        return (string) System::importStatic($callback[0])->{$callback[1]}($value, $this->dataContainer($pageId));
    }

    /**
     * A DC_Table carrying only the id — all `generateAlias()` reads from it. Built
     * without the constructor, which needs a request and a back-end user, the same
     * way CacheTagInvalidator does.
     */
    private function dataContainer(int $pageId): DataContainer
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();

        foreach (['intId' => $pageId, 'strTable' => 'tl_page'] as $property => $value) {
            (new \ReflectionProperty(DataContainer::class, $property))->setValue($dc, $value);
        }

        return $dc;
    }

    /**
     * @return list<int>
     */
    private function descendantIds(int $rootId): array
    {
        $ids     = [];
        $parents = [$rootId];

        for ($depth = 0; [] !== $parents && $depth < 20; ++$depth) {
            $children = array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_page WHERE pid IN (?)',
                [$parents],
                [ArrayParameterType::INTEGER],
            ));
            $ids     = [...$ids, ...$children];
            $parents = $children;
        }

        return $ids;
    }
}
