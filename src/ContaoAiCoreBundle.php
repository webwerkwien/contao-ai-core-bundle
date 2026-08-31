<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle;

use Contao\CalendarEventsModel;
use Contao\CommentsModel;
use Contao\FaqModel;
use Contao\NewsletterChannelModel;
use Contao\NewsModel;
// Hinweis für künftige Erweiterungen (Phase 9.4+):
//   - contao/listing-bundle    → braucht aktuell keinen Guard, weil der bestehende
//     ListingConfigCommand nur \Contao\ModuleModel (Core) referenziert.
// Sobald Commands für diese Plugins entstehen, hier nach demselben Muster
// (services_*.yaml + class_exists-Import) ergänzen.
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class ContaoAiCoreBundle extends AbstractBundle
{
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
    }
}
