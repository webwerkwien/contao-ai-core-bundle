<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle;

use Contao\CalendarEventsModel;
use Contao\CommentsModel;
use Contao\FaqModel;
use Contao\NewsModel;
// Hinweis für künftige Erweiterungen (Phase 9.4+):
//   - contao/newsletter-bundle → \Contao\NewsletterModel
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
    }
}
