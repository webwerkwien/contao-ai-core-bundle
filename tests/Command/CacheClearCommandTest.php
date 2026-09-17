<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Monolog\ContaoContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\CacheClearCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

/**
 * Clearing the internal cache leaves a trace, as it does in the back end.
 *
 * ⚪ **Nr. 50 of the ConpAI 1.0 acceptance test, 2026-09-17:** the audit of phase 5 found
 * every write in `tl_log` — except `cache clear`, which ran Symfony's `cache:clear` and
 * left nothing. Contao's back end logs "Purged the internal cache"
 * (`Automator::purgeInternalCache()`).
 */
class CacheClearCommandTest extends TestCase
{
    private function application(int $exitCode): Application
    {
        $application = new Application();
        $application->add(new class($exitCode) extends Command {
            public function __construct(private readonly int $exitCode)
            {
                parent::__construct('cache:clear');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln(' [OK] Cache for the "prod" environment (debug=false) was successfully cleared.');

                return $this->exitCode;
            }
        });

        return $application;
    }

    public function testAClearedCacheIsLogged(): void
    {
        $messages = [];
        $contexts = [];
        $logger   = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message, array $context) use (&$messages, &$contexts): void {
            $messages[] = $message;
            $contexts[] = $context;
        });

        $command = new CacheClearCommand();
        $command->setLogger($logger);
        $this->application(0)->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['--operator' => 'michael']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status'], $tester->getDisplay());
        $this->assertTrue($out['cleared']);
        $this->assertTrue($out['logged']);
        $this->assertStringContainsString('successfully cleared', $out['output']);
        $this->assertSame(['Purged the internal cache'], $messages);

        // What the "as the back end does" rests on: the cron action, the CLI origin, who.
        $contao = $contexts[0]['contao'];
        $this->assertInstanceOf(ContaoContext::class, $contao);
        $this->assertSame(ContaoContext::CRON, $contao->getAction());
        $this->assertSame(SystemLog::SOURCE, $contao->getSource());
        $this->assertSame('contao:cache:clear', $contao->getFunc());
        $this->assertSame('michael', $contao->getUsername());
    }

    public function testAFailedClearIsAnErrorAndNotLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $command = new CacheClearCommand();
        $command->setLogger($logger);
        $this->application(1)->add($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }
}
