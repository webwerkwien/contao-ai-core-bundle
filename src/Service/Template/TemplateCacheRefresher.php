<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Template;

use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * What Contao's Template Studio does after saving a template.
 *
 * In production Contao caches the template hierarchy, and Twig does not auto-reload
 * compiled templates. The Template Studio therefore refreshes both after every save
 * (`AbstractOperation::refreshTemplateHierarchy()`, `CacheInvalidator::invalidateCache()`
 * in 5.7). Until v0.19.0 `contao:template:write` did neither: a new variant was
 * refused as `customTpl` and an edited override kept rendering the old version until
 * `cache clear` (Nr. 47 of the ConpAI 1.0 acceptance test, 2026-09-17). See
 * TemplateCacheRefresherTest.
 *
 * Global templates only — `getInheritanceChains()` without a theme slug, as
 * CacheInvalidator does for a global template; `template:write` writes only there.
 */
final class TemplateCacheRefresher
{
    public function __construct(
        private readonly ContaoFilesystemLoader $loader,
        private readonly Environment $twig,
        #[Autowire(service: 'cache.system')]
        private readonly ?CacheItemPoolInterface $systemCache = null,
    ) {
    }

    /**
     * @return list<string> what could not be refreshed from here — empty when all was
     */
    public function refresh(): array
    {
        $warnings = [];

        $this->loader->warmUp(true);

        // Contao 5.3 keeps the hierarchy in cache.system. Where the web server has APCu,
        // that is an APCu layer the console never sees (apc.enable_cli is off by default),
        // so the web server may keep the old list. 5.7 uses cache.app, a filesystem pool
        // both share (review 2026-09-17).
        if ($this->usesSystemCache()) {
            $warnings[] = 'Contao keeps its template list in the system cache here (Contao 5.3); a web server with APCu may still use the old list — if the template is refused or not rendered, run cache clear.';
        }

        // Environment::removeCache() exists from Twig 3.15; Contao 5.3 allows ^3.10.2.
        if (!method_exists($this->twig, 'removeCache')) {
            $warnings[] = 'Twig before 3.15 cannot drop compiled templates; an edited override may render the old version until cache clear.';

            return $warnings;
        }

        // As CacheInvalidator: every template outside the back end, not only the one
        // written — an override changes what every template extending it renders.
        foreach ($this->loader->getInheritanceChains() as $identifier => $chain) {
            if (str_starts_with((string) $identifier, 'backend/')) {
                continue;
            }

            foreach ($chain as $logicalName) {
                $this->twig->removeCache($logicalName);
            }
        }

        return $warnings;
    }

    /**
     * Whether the loader's pool is the `cache.system` service — by identity, so a pool
     * wrapped for the profiler or configured to another adapter is still told apart
     * (review 2026-09-17: guessing by adapter class missed TraceableAdapter in debug).
     */
    private function usesSystemCache(): bool
    {
        if (null === $this->systemCache) {
            return false;
        }

        try {
            $pool = (new \ReflectionProperty(ContaoFilesystemLoader::class, 'cachePool'))->getValue($this->loader);
        } catch (\Throwable) {
            return false; // not readable (renamed, or a test double): say nothing rather than guess
        }

        return $pool === $this->systemCache;
    }
}
