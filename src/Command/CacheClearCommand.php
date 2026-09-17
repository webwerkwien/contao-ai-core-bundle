<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

/**
 * Clear the internal cache and log it, as Contao's back end does.
 *
 * Symfony's `cache:clear` leaves no trace. Contao's own purge in the back end logs
 * "Purged the internal cache" in the cron channel (`Automator::purgeInternalCache()`).
 * Until v0.20.0 a `cache clear` through the CLI was the only step of a whole build that
 * `tl_log` did not show (Nr. 50 of the ConpAI 1.0 acceptance test, 2026-09-17). See
 * CacheClearCommandTest.
 *
 * Runs Symfony's `cache:clear` in the same process and writes the entry afterwards. That
 * works because `cache:clear` keeps the running container's directory for in-process
 * callers (the `.legacy` marker): Contao's table handler resolves its database connection
 * lazily from that old container. If writing fails anyway, the answer says
 * `logged: false` — the cache is cleared either way. A container file that can no longer
 * be loaded would be a fatal error, not an exception; not seen on 5.3, 5.7 or 6.0.
 */
#[AsCommand(name: 'contao:cache:clear', description: 'Clear the internal cache (cache:clear) and log it as the back end does')]
class CacheClearCommand extends Command
{
    use JsonErrorBoundary;

    use OperatorOptionTrait;

    private ?LoggerInterface $logger = null;

    #[Required]
    public function setLogger(#[Autowire(service: 'monolog.logger.contao.cron')] LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this->addOperatorOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, fn (): int => $this->doExecute($input, $output));
    }

    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $clear = $this->getApplication()?->find('cache:clear');

        if (null === $clear) {
            $output->writeln((string) json_encode(['status' => 'error', 'message' => 'cache:clear is not available', 'code' => 1]));

            return self::FAILURE;
        }

        $buffer = new BufferedOutput();
        $code   = $clear->run(new ArrayInput(['--no-interaction' => true]), $buffer);
        $text   = trim($buffer->fetch());

        if (self::SUCCESS !== $code) {
            $output->writeln((string) json_encode(['status' => 'error', 'message' => "cache:clear failed (exit {$code})", 'output' => $text, 'code' => 1], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return self::FAILURE;
        }

        $logged = false;

        try {
            $this->logger?->info('Purged the internal cache', ['contao' => new ContaoContext(
                func: (string) $this->getName(),
                action: ContaoContext::CRON,
                username: $this->resolveOperatorName($input),
                source: SystemLog::SOURCE,
            )]);
            $logged = null !== $this->logger;
        } catch (\Throwable) {
            // cleared regardless; the answer says the entry is missing
        }

        $output->writeln((string) json_encode(['status' => 'ok', 'cleared' => true, 'logged' => $logged, 'output' => $text], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return self::SUCCESS;
    }
}
