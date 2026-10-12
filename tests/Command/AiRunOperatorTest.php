<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\AiRunCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

/**
 * contao:ai:run hands its --operator on to the command it runs (v1.7.1). In the Consho
 * chat test (2026-10-12) an editor's change was stored in tl_version under the shell
 * user: the operator reached the log entry only. Through the real command and a real
 * console application; the targets record what they were given.
 */
class AiRunOperatorTest extends TestCase
{
    /** @var list<array{operator: mixed, id: mixed}> */
    private array $received = [];

    /** @var list<string> */
    private array $logged = [];

    private ?CommandTester $tester = null;

    /**
     * @param array<string, string> $input
     */
    private function aiRun(array $input): int
    {
        $application = new Application();
        $application->setAutoExit(false);

        $record = function (mixed $operator, mixed $id): void {
            $this->received[] = ['operator' => $operator, 'id' => $id];
        };

        foreach (['contao:probe-operator' => true, 'contao:probe-plain' => false] as $name => $withOperator) {
            $application->add(new class($name, $withOperator, $record) extends Command {
                public function __construct(string $name, private readonly bool $withOperator, private readonly \Closure $record)
                {
                    parent::__construct($name);
                }

                protected function configure(): void
                {
                    $this->addArgument('id', InputArgument::OPTIONAL);
                    if ($this->withOperator) {
                        $this->addOption('operator', null, InputOption::VALUE_REQUIRED);
                    }
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    ($this->record)($input->hasOption('operator') ? $input->getOption('operator') : 'none defined', $input->getArgument('id'));

                    return self::SUCCESS;
                }
            });
        }

        $log = $this->createMock(SystemLog::class);
        $log->method('write')->willReturnCallback(function (string $message): void {
            $this->logged[] = $message;
        });

        $application->add($command = new AiRunCommand());
        $command->setSystemLog($log);

        $this->tester = new CommandTester($command);

        return $this->tester->execute($input);
    }

    public function testACommandWithAnOperatorOptionGetsTheOperator(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-operator -- "-5"', '--operator' => 'testredakteur']);

        $this->assertSame([['operator' => 'testredakteur', 'id' => '-5']], $this->received, 'an argument behind -- stays an argument');
        $this->assertStringContainsString('--operator "testredakteur"', $this->logged[0] ?? '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardNames(): iterable
    {
        yield 'quote and backslash' => ['o"brien\\x'];
        yield 'starts with a dash'  => ['-editor'];
        yield 'space'               => ['Maria Muster'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardNames')]
    public function testTheOperatorArrivesUnchanged(string $operator): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-operator 7', '--operator' => $operator]);

        $this->assertSame([['operator' => $operator, 'id' => '7']], $this->received);
    }

    public function testTheSameOperatorInTheLineRuns(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-operator --operator "editor" 7', '--operator' => 'editor']);

        $this->assertSame([['operator' => 'editor', 'id' => '7']], $this->received);
    }

    public function testAnotherOperatorInTheLineIsRefusedBeforeAnythingIsLoggedOrRuns(): void
    {
        $code = $this->aiRun(['--command-line' => 'contao:probe-operator --operator admin 7', '--operator' => 'editor']);

        $this->assertNotSame(Command::SUCCESS, $code);
        $this->assertSame([], $this->received);
        $this->assertSame([], $this->logged);
        $this->assertStringContainsString('Pass it once', (string) $this->tester?->getDisplay());
    }

    public function testACommandWithoutTheOptionGetsTheLineUnchanged(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-plain 7', '--operator' => 'editor']);

        $this->assertSame([['operator' => 'none defined', 'id' => '7']], $this->received);
        $this->assertSame(['contao:ai:run started an unwrapped command: contao:probe-plain 7'], $this->logged);
    }

    public function testWithoutAnOperatorTheCommandDecidesItself(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-operator 7']);

        $this->assertSame([['operator' => null, 'id' => '7']], $this->received);
    }
}
