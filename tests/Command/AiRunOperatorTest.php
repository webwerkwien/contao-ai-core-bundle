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

    /** @var list<array{message: string, operator: string}> */
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

        $targets = [
            'contao:probe-operator' => ['mode' => InputOption::VALUE_REQUIRED, 'shortcut' => 'o', 'ignore' => false],
            'contao:probe-ignore'   => ['mode' => InputOption::VALUE_REQUIRED, 'shortcut' => null, 'ignore' => true],
            'contao:probe-flag'     => ['mode' => InputOption::VALUE_NONE, 'shortcut' => null, 'ignore' => false],
            'contao:probe-plain'    => ['mode' => null, 'shortcut' => null, 'ignore' => false],
        ];

        foreach ($targets as $name => $spec) {
            $application->add(new class($name, $spec, $record) extends Command {
                /** @param array{mode: int|null, shortcut: string|null, ignore: bool} $spec */
                public function __construct(string $name, private readonly array $spec, private readonly \Closure $record)
                {
                    parent::__construct($name);
                }

                protected function configure(): void
                {
                    $this->addArgument('id', InputArgument::OPTIONAL);
                    if (null !== $this->spec['mode']) {
                        $this->addOption('operator', $this->spec['shortcut'], $this->spec['mode']);
                    }
                    if ($this->spec['ignore']) {
                        $this->ignoreValidationErrors();
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
        $log->method('write')->willReturnCallback(function (string $message, string $action, string $operator): void {
            $this->logged[] = ['message' => $message, 'operator' => $operator];
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
        $this->assertStringContainsString('--operator "testredakteur"', $this->logged[0]['message'] ?? '');
        $this->assertSame('testredakteur', $this->logged[0]['operator'] ?? null);
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

    public function testANameTheConsoleCannotPassIsRefused(): void
    {
        $code = $this->aiRun(['--command-line' => 'contao:probe-operator 7', '--operator' => "-a''b"]);

        $this->assertNotSame(Command::SUCCESS, $code);
        $this->assertSame([], $this->received);
        $this->assertSame([], $this->logged);
    }

    public function testTheLogAndTheCommandGetTheSameTrimmedOperator(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-operator 7', '--operator' => '  editor  ']);

        $this->assertSame('editor', $this->received[0]['operator'] ?? null);
        $this->assertSame('editor', $this->logged[0]['operator'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theSameUser(): iterable
    {
        yield 'own token'       => ['contao:probe-operator --operator "editor" 7'];
        yield '= form'          => ['contao:probe-operator --operator=editor 7'];
        yield 'shortcut'        => ['contao:probe-operator -o editor 7'];
        yield 'with a space'    => ['contao:probe-operator --operator " editor" 7'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('theSameUser')]
    public function testTheSameOperatorInTheLineRuns(string $line): void
    {
        $this->aiRun(['--command-line' => $line, '--operator' => 'editor']);

        $this->assertSame('7', $this->received[0]['id'] ?? null);
        $this->assertSame('editor', trim((string) ($this->received[0]['operator'] ?? '')));
    }

    /**
     * Every way of naming another user, or none, in the line (review S1, S2, S3).
     *
     * @return iterable<string, array{string}>
     */
    public static function anotherUser(): iterable
    {
        yield 'own token'                         => ['contao:probe-operator --operator admin 7'];
        yield '= form'                            => ['contao:probe-operator --operator=admin 7'];
        yield 'shortcut'                          => ['contao:probe-operator -o admin 7'];
        yield 'shortcut glued'                    => ['contao:probe-operator -oadmin 7'];
        yield 'repeated, the last wins'           => ['contao:probe-operator --operator editor --operator admin 7'];
        yield 'empty'                             => ['contao:probe-operator --operator "" 7'];
        yield 'empty = form'                      => ['contao:probe-operator --operator= 7'];
        yield 'ignoring target, before an error'  => ['contao:probe-ignore --operator admin --bogus 7'];
        yield 'ignoring target, after an error'   => ['contao:probe-ignore --bogus --operator admin 7'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('anotherUser')]
    public function testAnotherOrNoOperatorInTheLineIsRefusedBeforeAnythingIsLoggedOrRuns(string $line): void
    {
        $code = $this->aiRun(['--command-line' => $line, '--operator' => 'editor']);

        $this->assertNotSame(Command::SUCCESS, $code);
        $this->assertSame([], $this->received);
        $this->assertSame([], $this->logged);
        $this->assertStringContainsString('Pass it once', (string) $this->tester?->getDisplay());
    }

    public function testACommandWithoutTheOptionGetsTheLineUnchanged(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-plain 7', '--operator' => 'editor']);

        $this->assertSame([['operator' => 'none defined', 'id' => '7']], $this->received);
        $this->assertSame('contao:ai:run started an unwrapped command: contao:probe-plain 7', $this->logged[0]['message'] ?? null);
    }

    public function testAnOperatorFlagGetsNoUser(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-flag 7', '--operator' => 'editor']);

        $this->assertSame([['operator' => false, 'id' => '7']], $this->received);
    }

    public function testWithoutAnOperatorTheCommandDecidesItself(): void
    {
        $this->aiRun(['--command-line' => 'contao:probe-operator 7']);

        $this->assertSame([['operator' => null, 'id' => '7']], $this->received);
    }
}
