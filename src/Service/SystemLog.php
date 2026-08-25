<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes an entry into Contao's system log (tl_log).
 *
 * Everything this bundle changed used to be invisible in the back end. The
 * commands did log, but through the plain app-channel `LoggerInterface`, and in
 * a Managed Edition that channel reaches neither `tl_log` nor `var/logs` — a
 * `grep` over a production log directory after weeks of CLI edits returned
 * nothing at all.
 *
 * Two things are needed to reach `tl_log`. First the `contao.*` channel, which
 * Contao's LoggerChannelPass decorates with its SystemLogger. Second a
 * `ContaoContext` in the log context: `ContaoTableHandler::handle()` returns
 * early without one, so an entry on the right channel still goes nowhere if the
 * context is missing. The decorator would add a bare context by itself, but
 * ContaoTableProcessor only fills what is still null — and on the console there
 * is neither a request nor a security token, so it would end up as
 * `username = N/A`, `source = FE`. Passing our own context is what makes the
 * entry attributable.
 */
class SystemLog
{
    /**
     * `tl_log.source` is a plain varchar filter column; Contao itself only ever
     * writes BE or FE. A console write is neither, and calling it BE would be a
     * lie in the one column an operator uses to ask "did a person do this?".
     * The back-end filter falls back to the raw value when no translation
     * exists (ValueFormatter guards with isset), so this shows up as "CLI".
     */
    public const SOURCE = 'CLI';

    public function __construct(
        #[Autowire(service: 'monolog.logger.contao.general')]
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param string $text     what is shown in the back end's log list
     * @param string $func     the command name, kept in tl_log.func
     * @param string $username the operator, see AbstractWriteCommand::resolveOperator()
     * @param string $action   one of the ContaoContext action constants
     */
    public function write(
        string $text,
        string $func,
        string $username,
        string $action = ContaoContext::GENERAL,
    ): void {
        // Only claim CLI when there really is no request. contao-ai-backend-bundle
        // runs these very commands in-process during a back-end request, and those
        // are BE writes - labelling them CLI would misattribute an editor's change
        // to the console. With source left null, ContaoTableProcessor reads the
        // request and fills in BE (or FE) itself.
        $source = null === $this->requestStack->getCurrentRequest() ? self::SOURCE : null;

        $this->logger->info($text, ['contao' => new ContaoContext(
            func: '' !== $func ? $func : 'contao-ai-core-bundle',
            action: $action,
            username: '' !== $username ? $username : 'cli-agent',
            source: $source,
        )]);
    }
}
