<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentFindCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\ChangeLanguageLinks;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LanguageVersions;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LinkTargetDiagnosis;

/**
 * What the container puts into content:find's optional argument (v1.11.0, rule 25): the
 * real services files through Symfony's own loader, not a `new` in the test. Without
 * changelanguage the service is not registered and the argument must be null rather than
 * a failing container; with it, the service.
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

    /**
     * v1.11.1: the link refusal names the unpublished page only if the container hands
     * ChangeLanguageLinks its diagnosis — an optional argument, so a missing one would
     * fall back to the general rule without anybody noticing.
     */
    public function testTheLinkRulesGetTheirDiagnosis(): void
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
        $loader->load('services_changelanguage.yaml');

        foreach (array_keys($container->getDefinitions()) as $id) {
            if (!\in_array($id, [ChangeLanguageLinks::class, LinkTargetDiagnosis::class, 'service_container'], true)) {
                $container->removeDefinition($id);
            }
        }
        foreach (array_keys($container->getAliases()) as $alias) {
            $container->removeAlias($alias);
        }
        foreach ([Connection::class, OptionsResolver::class] as $synthetic) {
            $container->register($synthetic, $synthetic)->setSynthetic(true)->setPublic(true);
        }
        $container->getDefinition(ChangeLanguageLinks::class)->setPublic(true);
        $container->compile();
        $container->set(Connection::class, DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $container->set(OptionsResolver::class, $this->createStub(OptionsResolver::class));

        $diagnosis = (new \ReflectionProperty(ChangeLanguageLinks::class, 'diagnosis'))->getValue($container->get(ChangeLanguageLinks::class));
        self::assertInstanceOf(LinkTargetDiagnosis::class, $diagnosis);
    }
}
