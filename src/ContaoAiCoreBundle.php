<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle;

use Contao\CalendarEventsModel;
use Contao\CommentsModel;
use Contao\FaqModel;
use Contao\NewsletterChannelModel;
use Contao\NewsModel;
// Note for future extensions:
//   - contao/listing-bundle    → currently needs no guard, because the existing
//     ListingConfigCommand only references \Contao\ModuleModel (core).
// As soon as commands for these plugins appear, add them here following the same
// pattern (services_*.yaml + class_exists import).
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

        // Plugin-dependent commands: register only when the respective
        // Contao bundle (news/calendar/faq/comments) is installed. Otherwise
        // the container build fails with a ReflectionException, because the
        // referenced model class does not exist.
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
        // The guard hangs on the channel model, not on NewsletterModel: the channel
        // is the root table without which neither newsletters nor recipients
        // can exist — and all nine commands need it.
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
