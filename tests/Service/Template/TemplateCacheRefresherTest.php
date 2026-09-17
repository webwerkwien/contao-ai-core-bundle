<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Template;

use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Webwerkwien\ContaoAiCoreBundle\Service\Template\TemplateCacheRefresher;

/**
 * A written template is usable at once — as after saving in the Template Studio.
 *
 * 🟡 **Nr. 47 of the ConpAI 1.0 acceptance test, 2026-09-17:** `template write --mode
 * variant --name consho_hero` wrote the file, `template list` showed it — and
 * `content create --set customTpl=content_element/text/consho_hero` refused it as
 * "not a template Contao offers" until `cache clear`. In production Contao keeps the
 * template hierarchy cached, and Twig does not auto-reload compiled templates.
 *
 * Contao's Template Studio does two things after saving
 * (`AbstractOperation::refreshTemplateHierarchy()` and `invalidateTemplateCache()`):
 * `ContaoFilesystemLoader::warmUp(true)`, then `Environment::removeCache()` for every
 * template name outside `backend/` (`CacheInvalidator`, 5.7).
 */
class TemplateCacheRefresherTest extends TestCase
{
    public function testTheHierarchyIsRebuiltAndCompiledTemplatesAreDropped(): void
    {
        $loader = $this->createMock(ContaoFilesystemLoader::class);
        $loader->expects($this->once())->method('warmUp')->with(true);
        $loader->method('getInheritanceChains')->willReturn([
            'content_element/text' => [
                '/app/templates/content_element/text/consho_hero.html.twig' => '@Contao_Global/content_element/text/consho_hero.html.twig',
                '/app/vendor/contao/core-bundle/contao/templates/twig/content_element/text.html.twig' => '@Contao_ContaoCoreBundle/content_element/text.html.twig',
            ],
            'backend/be_main' => [
                '/app/vendor/contao/core-bundle/contao/templates/backend/be_main.html.twig' => '@Contao_ContaoCoreBundle/backend/be_main.html.twig',
            ],
        ]);

        $removed = [];
        $twig = $this->createMock(Environment::class);
        $twig->method('removeCache')->willReturnCallback(static function (string $name) use (&$removed): void {
            $removed[] = $name;
        });

        $warnings = (new TemplateCacheRefresher($loader, $twig))->refresh();

        $this->assertSame([], $warnings);
        $this->assertSame([
            '@Contao_Global/content_element/text/consho_hero.html.twig',
            '@Contao_ContaoCoreBundle/content_element/text.html.twig',
        ], $removed);
    }

    /**
     * Review 2026-09-17: Contao 5.3 keeps the hierarchy in cache.system — with APCu a
     * layer the console cannot reach. Contao 5.7 uses cache.app, a filesystem pool.
     */
    public function testALoaderOnTheSystemCacheIsReportedAsNotFullyRefreshed(): void
    {
        $systemCache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        $twig        = $this->createMock(Environment::class);

        $this->assertCount(1, (new TemplateCacheRefresher($this->loaderOn($systemCache), $twig, $systemCache))->refresh());
        $this->assertSame([], (new TemplateCacheRefresher($this->loaderOn(new \Symfony\Component\Cache\Adapter\ArrayAdapter()), $twig, $systemCache))->refresh());
    }

    private function loaderOn(\Psr\Cache\CacheItemPoolInterface $pool): ContaoFilesystemLoader
    {
        $loader = $this->getMockBuilder(ContaoFilesystemLoader::class)
            ->setConstructorArgs([
                $pool,
                $this->createMock(\Contao\CoreBundle\Twig\Loader\TemplateLocator::class),
                $this->createMock(\Contao\CoreBundle\Twig\Loader\ThemeNamespace::class),
                $this->createMock(\Contao\CoreBundle\Framework\ContaoFramework::class),
                sys_get_temp_dir(),
            ])
            ->onlyMethods(['warmUp', 'getInheritanceChains'])
            ->getMock();
        $loader->method('getInheritanceChains')->willReturn([]);

        return $loader;
    }
}
