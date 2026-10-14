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
    private function runConsole(array $argv): string
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
        $application->add(new AcmeRefuseCommand());
        $application->add(new AcmePlainCommand());
        $application->add(new AcmeGuardedCommand());

        $log = $this->createMock(SystemLog::class);
        $application->add($run = new AiRunCommand());
        $run->setSystemLog($log);

        $application->run(new ArgvInput(['console', ...$argv]), $output = new BufferedOutput());

        $this->records = $logger->records;

        return $output->fetch();
    }

    /** @return list<string> the levels logged, in order */
    private function levels(): array
    {
        return array_column($this->records, 0);
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

    /**
     * Fable review before v1.6.0: at -vvv the boundary rethrows, so contao:ai:run logs
     * the error too — with the inner line blanked whole, and a message quoting only the
     * pin, which a value list of `S3CRET --set name=a --pin …` did not strike.
     */
    public function testAtVeryVerboseContaoAiRunStrikesEveryValueOfTheInnerLine(): void
    {
        try {
            $this->runConsole(['contao:ai:run', '-vvv', '--command-line=acme:fail --set password=S3CRET-ONE --set name=a --pin S3CRET-PIN']);
        } finally {
            // Symfony exports the verbosity; later tests would run at -vvv.
            putenv('SHELL_VERBOSITY');
            unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
        }

        $logged = $this->logged();
        $this->assertStringContainsString('Error thrown while running command "contao:ai:run', $logged, 'the outer error is logged at -vvv');
        $this->assertStringNotContainsString('S3CRET', $logged);
    }

    /** A secret that is no valid UTF-8 cannot be struck by pattern: the message is withheld. */
    public function testASecretThatIsNoValidUtf8WithholdsTheMessage(): void
    {
        // Without a warning: a strict error handler would turn one into an exception
        // inside the listener.
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $this->runConsole(['acme:fail', '--set', "password=\xB1\x31"]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $logged = $this->logged();
        $this->assertStringContainsString('Message: "' . RedactingConsoleErrorListener::MESSAGE_WITHHELD . '"', $logged);
        $this->assertStringNotContainsString("\xB1\x31", $logged);
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

    /**
     * v1.7.2 (Consho, c5, 2026-10-13): a refused input stood as CRITICAL next to real crashes.
     * Still struck: the message names the pin.
     */
    public function testARefusalOfACommandWithAContractIsAWarning(): void
    {
        $this->runConsole(['acme:refuse', '--pin', 'S3CRET-PIN']);

        $logged = $this->logged();
        $this->assertSame(['warning', 'debug'], $this->levels());
        $this->assertStringContainsString('Command "acme:refuse --pin" refused its input. Message: "--product is required: pin ***"', $logged);
        $this->assertStringNotContainsString('S3CRET', $logged);
    }

    /**
     * v1.8.1 (Consho): a refused line carried every value the caller typed — names,
     * addresses, texts — not only the declared secrets. Now the option names only, in the
     * warning and in the debug line Contao's log flushes with a later error.
     */
    public function testARefusalIsLoggedWithTheOptionNamesOnly(): void
    {
        $this->runConsole(['acme:refuse', '--pin', 'S3CRET-PIN', '--note', 'Anna Muster, Hauptstraße 1']);

        $logged = $this->logged();
        $this->assertStringContainsString('Command "acme:refuse --pin --note" refused its input', $logged);
        $this->assertStringContainsString('Command "acme:refuse --pin --note" exited with code', $logged);
        $this->assertStringNotContainsString('Anna', $logged);
    }

    /**
     * Second Fable review: the names came from a regex over the quoted line, and a value
     * like "Hallo --welt" or "Max -Anna Muster" put `--welt -Anna` into the log.
     */
    public function testWordsInsideAValueThatLookLikeOptionsStayOut(): void
    {
        $this->runConsole(['acme:refuse', '--note', 'Hallo --welt, Max -Anna Muster']);

        $logged = $this->logged();
        $this->assertStringContainsString('Command "acme:refuse --note" refused its input', $logged);
        $this->assertStringNotContainsString('welt', $logged);
        $this->assertStringNotContainsString('Anna', $logged);
    }

    /**
     * Fable review before v1.8.1: this bundle's commands answer a refusal through
     * JsonErrorBoundary, so no console.error fires — and the debug exit line, the only
     * one written, still carried every value. Measured with a real application.
     */
    public function testACommandAnsweringThroughTheBoundaryIsLoggedWithOptionNamesOnly(): void
    {
        $answer = $this->runConsole(['acme:guarded', '--note', 'Anna Muster, Hauptstraße 1']);

        $this->assertStringContainsString('"status":"error"', $answer, 'answered by the boundary');
        $this->assertSame(['debug'], $this->levels(), 'no console.error, only the exit line');
        $this->assertStringContainsString('Command "acme:guarded --note" exited with code "1"', $this->logged());
        $this->assertStringNotContainsString('Anna', $this->logged());
    }

    public function testThroughContaoAiRunARefusalIsAWarningAndStillAnsweredAsOne(): void
    {
        $answer = $this->runConsole(['contao:ai:run', '--command-line=acme:refuse']);

        $this->assertNotContains('critical', $this->levels());
        $this->assertStringContainsString('Command "acme:refuse" refused its input', $this->logged());
        $this->assertStringContainsString('"message":"InvalidArgumentException: --product is required', $answer);
        $this->assertStringNotContainsString('"exception"', $answer, 'a refusal, not a defect');
    }

    /**
     * Fable review before v1.7.2: at -vvv the boundary rethrows, and contao:ai:run (no
     * contract) logs it CRITICAL on top of the target's warning. Deliberate: the debug path.
     */
    public function testAtVeryVerboseContaoAiRunLogsTheRefusalCriticalOnTop(): void
    {
        try {
            $this->runConsole(['contao:ai:run', '-vvv', '--command-line=acme:refuse']);
        } finally {
            putenv('SHELL_VERBOSITY');
            unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
        }

        $logged = $this->logged();
        $this->assertContains('warning', $this->levels());
        $this->assertStringContainsString('Command "acme:refuse" refused its input', $logged);
        $this->assertStringContainsString('Error thrown while running command "contao:ai:run -vvv', $logged);
    }

    /**
     * Symfony's subclasses are mostly programming errors; a command without a contract has not
     * said it follows the convention; anything else is a defect.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function staysCritical(): iterable
    {
        yield 'a subclass of InvalidArgumentException' => [['acme:refuse', '--as', 'console']];
        yield 'another exception' => [['acme:refuse', '--as', 'runtime']];
        yield 'no contract' => [['acme:plain']];
    }

    /**
     * @param list<string> $argv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('staysCritical')]
    public function testEverythingElseStaysCritical(array $argv): void
    {
        $this->runConsole($argv);

        $this->assertContains('critical', $this->levels());
        $this->assertNotContains('warning', $this->levels());
        $this->assertStringContainsString('Error thrown while running command "acme:', $this->logged());
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

#[AiContract(writes: false, secrets: ['pin'])]
final class AcmeRefuseCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('acme:refuse')
            ->addOption('pin', null, InputOption::VALUE_REQUIRED)
            ->addOption('note', null, InputOption::VALUE_REQUIRED)
            ->addOption('as', null, InputOption::VALUE_REQUIRED, '', 'refusal');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = '--product is required: pin ' . $input->getOption('pin');

        throw match ($input->getOption('as')) {
            'console' => new \Symfony\Component\Console\Exception\InvalidArgumentException($message),
            'runtime' => new \RuntimeException($message),
            default   => new \InvalidArgumentException($message),
        };
    }
}

/** No contract, answers through JsonErrorBoundary — the shape of this bundle's commands. */
final class AcmeGuardedCommand extends Command
{
    use \Webwerkwien\ContaoAiCoreBundle\Command\JsonErrorBoundary;

    protected function configure(): void
    {
        $this->setName('acme:guarded')->addOption('note', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guarded($output, static function (): int {
            throw new \InvalidArgumentException('--product is required');
        });
    }
}

final class AcmePlainCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('acme:plain');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new \InvalidArgumentException('--product is required');
    }
}
