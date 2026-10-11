<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\DependencyInjection\Compiler;

use Symfony\Component\Console\EventListener\ErrorListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webwerkwien\ContaoAiCoreBundle\EventListener\RedactingConsoleErrorListener;

/**
 * Puts {@see RedactingConsoleErrorListener} in the place of Symfony's console error
 * listener (v1.6.0).
 *
 * The class is swapped on the existing service rather than decorating it: the
 * definition keeps its logger argument, its `console` channel and its subscriber tag,
 * whatever the framework and MonologBundle versions make of them — the class has the
 * same constructor. A decorator would have to restate all three and get each right
 * on 5.3, 5.7 and 6.0.
 *
 * Only Symfony's own class is replaced. Where an installation has put another one
 * there, it stays: replacing it would drop what it does, and nothing here knows what.
 */
final class RedactingConsoleErrorListenerPass implements CompilerPassInterface
{
    public const SERVICE = 'console.error_listener';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SERVICE)) {
            return;
        }

        $definition = $container->getDefinition(self::SERVICE);

        if (ErrorListener::class !== $container->getParameterBag()->resolveValue($definition->getClass())) {
            return;
        }

        $definition->setClass(RedactingConsoleErrorListener::class);
    }
}
