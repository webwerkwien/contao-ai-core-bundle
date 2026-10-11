<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\EventListener\ErrorListener;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiCoreBundle\Command\AiRunCommand;
use Webwerkwien\ContaoAiCoreBundle\DependencyInjection\Compiler\RedactingConsoleErrorListenerPass;
use Webwerkwien\ContaoAiCoreBundle\EventListener\RedactedConsoleError;
use Webwerkwien\ContaoAiCoreBundle\EventListener\RedactingConsoleErrorListener;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

/**
 * A failing command's line reaches the console log without its secrets (v1.6.0).
 * Through a real console application and event dispatcher; only the logger is a stand-in.
 */
class RedactingConsoleErrorListenerTest extends TestCase
{
    /** @var list<array{string, string}> level and interpolated message */
    private array $records = [];

    /**
     * @param list<string> $argv
     */
    private function runConsole(array $argv): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $replace = [];

                foreach ($context as $key => $value) {
                    if (\is_scalar($value)) {
                        $replace['{' . $key . '}'] = (string) $value;
                    }
                }

                $line = strtr((string) $message, $replace);

                // What the log serialises of the exception: class, message, file:line, each previous.
                for ($e = $context['exception'] ?? null; $e instanceof \Throwable; $e = $e->getPrevious()) {
                    $line .= ' | ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                }

                $this->records[] = [(string) $level, $line];
            }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RedactingConsoleErrorListener($logger));

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $application->setDispatcher($dispatcher);
        $application->add(new AcmeFailCommand());

        $log = $this->createMock(SystemLog::class);
        $application->add($run = new AiRunCommand());
        $run->setSystemLog($log);

        $application->run(new ArgvInput(['console', ...$argv]), new BufferedOutput());

        $this->records = $logger->records;
    }

    private function logged(): string
    {
        return implode("\n", array_column($this->records, 1));
    }

    public function testAFailingCommandIsLoggedWithoutItsSecrets(): void
    {
        $this->runConsole(['acme:fail', '--set', 'password=S3CRET-ONE', '--set', 'title=Laden', '--pin', 'S3CRET-PIN']);

        $logged = $this->logged();

        $this->assertStringContainsString('Error thrown while running command "acme:fail', $logged);
        $this->assertStringContainsString('password=***', $logged);
        $this->assertStringContainsString('title=Laden', $logged);
        $this->assertStringContainsString('--pin ***', $logged, 'declared in the contract');
        $this->assertStringNotContainsString('S3CRET', $logged);
        $this->assertContains('critical', array_column($this->records, 0));
        $this->assertContains('debug', array_column($this->records, 0), 'the exit code line as well');
    }

    public function testContaoAiRunIsLoggedWithoutTheSecretsOfTheLineItRuns(): void
    {
        $this->runConsole(['contao:ai:run', '--command-line=acme:fail --set name=a --set password=S3CRET-ONE --pin S3CRET-PIN']);

        $logged = $this->logged();

        // The run of acme:fail inside, and contao:ai:run around it: both name the line.
        $this->assertStringContainsString('Error thrown while running command "acme:fail', $logged);
        $this->assertStringContainsString('Command "contao:ai:run', $logged);
        $this->assertStringContainsString('name=a', $logged);
        $this->assertStringNotContainsString('S3CRET', $logged, 'pin is declared by acme:fail, not by contao:ai:run');
    }

    public function testAMessageWithoutASecretIsLeftAsItIs(): void
    {
        $this->runConsole(['acme:fail', '--set', 'title=x']);

        $logged = $this->logged();
        $this->assertStringContainsString('Message: "acme:fail failed on purpose"', $logged);
        $this->assertStringContainsString('| RuntimeException: acme:fail failed on purpose', $logged, 'the exception itself, not a stand-in');
    }

    /**
     * Found on c5: Symfony's binding errors quote a token. A `--set` forgotten, and
     * the password stood in the message and in the serialised exception.
     */
    public function testSymfonysMessageQuotingAValueIsStruck(): void
    {
        $this->runConsole(['acme:fail', 'password=S3CRET-ARG']);

        $logged = $this->logged();
        $this->assertStringContainsString('got "password=***"', $logged);
        $this->assertStringContainsString('| ' . RedactedConsoleError::class . ': Symfony\Component\Console\Exception\RuntimeException: No arguments expected', $logged);
        $this->assertStringNotContainsString('S3CRET', $logged);
    }

    public function testEveryPreviousMessageIsStruckAndTheLocationKept(): void
    {
        $this->runConsole(['acme:fail', '--pin', '7']);

        $logged = $this->logged();
        // A short value only as a word: "step 17" keeps its 7.
        $this->assertStringContainsString('LogicException: pin *** refused at step 17 @ RedactingConsoleErrorListenerTest.php:', $logged);
        $this->assertStringContainsString('RuntimeException: acme:fail failed on purpose @ RedactingConsoleErrorListenerTest.php:', $logged);
        $this->assertStringNotContainsString('pin 7', $logged);
    }

    public function testThePassPutsTheClassOnSymfonysService(): void
    {
        $container = new ContainerBuilder();
        $container->register('console.error_listener', ErrorListener::class)->addTag('kernel.event_subscriber');

        (new RedactingConsoleErrorListenerPass())->process($container);

        $definition = $container->getDefinition('console.error_listener');
        $this->assertSame(RedactingConsoleErrorListener::class, $definition->getClass());
        $this->assertTrue($definition->hasTag('kernel.event_subscriber'), 'tags stay where they are');
    }

    public function testThePassLeavesAnotherListenerAndAMissingServiceAlone(): void
    {
        $container = new ContainerBuilder();
        $container->register('console.error_listener', \stdClass::class);

        (new RedactingConsoleErrorListenerPass())->process($container);
        (new RedactingConsoleErrorListenerPass())->process(new ContainerBuilder());

        $this->assertSame(\stdClass::class, $container->getDefinition('console.error_listener')->getClass());
    }
}

#[AiContract(writes: false, secrets: ['pin'])]
final class AcmeFailCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('acme:fail')
            ->addOption('pin', null, InputOption::VALUE_REQUIRED)
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pin = $input->getOption('pin');

        throw new \RuntimeException('acme:fail failed on purpose', 0, null === $pin ? null : new \LogicException('pin ' . $pin . ' refused at step 17'));
    }
}
