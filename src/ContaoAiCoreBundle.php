<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle;

use Contao\CalendarEventsModel;
use Contao\CommentsModel;
use Contao\FaqModel;
use Contao\NewsletterChannelModel;
use Contao\NewsModel;
// Hinweis für künftige Erweiterungen:
//   - contao/listing-bundle    → braucht aktuell keinen Guard, weil der bestehende
//     ListingConfigCommand nur \Contao\ModuleModel (Core) referenziert.
// Sobald Commands für diese Plugins entstehen, hier nach demselben Muster
// (services_*.yaml + class_exists-Import) ergänzen.
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Webwerkwien\ContaoAiCoreBundle\DependencyInjection\Compiler\RedactingConsoleErrorListenerPass;

class ContaoAiCoreBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Symfony's console error listener logs a failing command's line, secrets
        // included; ours blanks them first (v1.6.0).
        $container->addCompilerPass(new RedactingConsoleErrorListenerPass());
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $containerConfigurator,
        ContainerBuilder $containerBuilder,
    ): void {
        $containerConfigurator->import('../config/services.yaml');

        // Plugin-bedingte Commands: nur registrieren, wenn das jeweilige
        // Contao-Bundle (news/calendar/faq/comments) installiert ist. Sonst
        // bricht der Container-Build mit einem ReflectionException, weil das
        // referenzierte Modell-Klasse nicht existiert.
        if (class_exists(NewsModel::class)) {
            $containerConfigurator->import('../config/services_news.yaml');
        }
        if (class_exists(CalendarEventsModel::class)) {
            $containerConfigurator->import('../config/services_calendar.yaml');
        }
        if (class_exists(FaqModel::class)) {
            $containerConfigurator->import('../config/services_faq.yaml');
        }
        if (class_exists(CommentsModel::class)) {
            $containerConfigurator->import('../config/services_comments.yaml');
        }
        // Der Guard hängt am Kanal-Modell, nicht an NewsletterModel: der Kanal
        // ist die Wurzeltabelle, ohne die weder Newsletter noch Empfänger
        // existieren können — und alle neun Commands brauchen ihn.
        if (class_exists(NewsletterChannelModel::class)) {
            $containerConfigurator->import('../config/services_newsletter.yaml');
        }
        // Not a Contao bundle but the one extension the core reaches into (v1.4.0):
        // translation links are fields on tables the core writes anyway, and the
        // commands use the package's own PageFinder.
        if (class_exists(\Terminal42\ChangeLanguage\PageFinder::class)) {
            $containerConfigurator->import('../config/services_changelanguage.yaml');
        }
    }
}
