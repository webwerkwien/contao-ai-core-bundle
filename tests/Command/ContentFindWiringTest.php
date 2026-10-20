<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentFindCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LanguageVersions;

/**
 * What the container puts into content:find's optional argument (v1.11.0, rule 25): the
 * real services files through Symfony's own loader, not a `new` in the test. Without
 * changelanguage the service is not registered and the argument must be null rather than
 * a failing container; with it, the service. Taken over from the review (2026-10-20).
 */
class ContentFindWiringTest extends TestCase
{
    private static function versionsArgument(bool $changelanguage): ?object
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
        if ($changelanguage) {
            $loader->load('services_changelanguage.yaml');
        }

        foreach (array_keys($container->getDefinitions()) as $id) {
            if (!\in_array($id, [ContentFindCommand::class, LanguageVersions::class, 'service_container'], true)) {
                $container->removeDefinition($id);
            }
        }
        foreach (array_keys($container->getAliases()) as $alias) {
            $container->removeAlias($alias);
        }
        $container->register(Connection::class, Connection::class)->setSynthetic(true)->setPublic(true);
        $container->getDefinition(ContentFindCommand::class)->setPublic(true);
        $container->compile();
        $container->set(Connection::class, DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        return (new \ReflectionProperty(ContentFindCommand::class, 'versions'))->getValue($container->get(ContentFindCommand::class));
    }

    public function testWithoutChangelanguageTheArgumentIsNull(): void
    {
        self::assertNull(self::versionsArgument(false));
    }

    public function testWithChangelanguageTheServiceIsPassed(): void
    {
        self::assertInstanceOf(LanguageVersions::class, self::versionsArgument(true));
    }
}
