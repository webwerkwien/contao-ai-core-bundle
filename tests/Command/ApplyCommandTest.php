<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ApplyCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;

/**
 * contao:ai:apply runs the bundle's own commands in one transaction, all or nothing
 * (v1.13.0). Through a real console application; the stand-ins under the real names
 * record what they were given and answer like the real ones.
 */
class ApplyCommandTest extends TestCase
{
    private const STAND_INS = ['contao:page:create', 'contao:content:create', 'contao:content:update', 'contao:language:link'];

    private const PLAN = <<<'JSON'
        {"steps": [
          {"ref": "versand", "do": "page create", "title": "Shipping", "pid": 59},
          {"do": "language link", "table": "tl_page", "id": "@versand", "main": 92},
          {"ref": "intro", "do": "content create", "type": "text", "pid": "@versand.article", "ptable": "tl_article",
           "set": {"text": "<p>{{link_url::@versand}} info@example.com</p>", "headline": {"unit": "h2", "value": "Hi"}}},
          {"do": "content update", "id": 771, "set": {"text": "{{insert_content::@intro}}"}}
        ]}
        JSON;

    /** @var list<string> */
    private array $log = [];

    /** @var list<array{name: string, id: mixed, options: array<string, mixed>}> */
    private array $received = [];

    /** @var list<string> */
    private array $invalidated = [];

    private int $next = 100;

    public function testAllStepsRunInOneTransactionAndReferencesReachTheCommands(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, ['--operator' => 'redakteur']);

        $this->assertSame(Command::SUCCESS, $code, json_encode($answer));
        $this->assertSame(['begin', 'commit'], $this->log);
        $this->assertSame(['versand' => 100, 'versand.article' => 1000, 'intro' => 102], $answer['refs']);

        [$page, $link, $element, $update] = $this->received;
        $this->assertSame('59', $page['options']['pid']);
        $this->assertSame('100', $link['id']);
        $this->assertSame('1000', $element['options']['pid']);
        $this->assertContains('text=<p>{{link_url::100}} info@example.com</p>', $element['options']['set']);
        $this->assertContains('headline={"unit":"h2","value":"Hi"}', $element['options']['set']);
        $this->assertSame('771', $update['id']);
        $this->assertContains('text={{insert_content::102}}', $update['options']['set']);
        $this->assertSame('redakteur', $page['options']['operator']);
    }

    public function testTheCacheIsInvalidatedAgainAfterTheCommit(): void
    {
        $this->apply(self::PLAN);

        $this->assertSame(['contao.db.x.100', 'contao.db.x.101', 'contao.db.x.102', 'contao.db.x.103'], $this->invalidated);
    }

    public function testAFailingStepRollsEverythingBackAndStopsTheRun(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, failOn: 'contao:content:create');

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['begin', 'rollback'], $this->log);
        $this->assertCount(3, $this->received, 'the update after the failing step never ran');
        $this->assertTrue($answer['rolledBack']);
        $this->assertSame(2, $answer['failedStep']['index']);
        $this->assertStringContainsString('refused by the stand-in', $answer['failedStep']['message']);
        $this->assertSame([], $this->invalidated);
    }

    public function testADryRunRunsEveryStepAndRollsBack(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, ['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame(['begin', 'rollback'], $this->log);
        $this->assertCount(4, $this->received);
        $this->assertTrue($answer['dryRun']);
        $this->assertSame([], $this->invalidated);
    }

    public function testARefusedPlanOpensNoTransaction(): void
    {
        [$code, $answer] = $this->apply('{"steps": [{"do": "page get"}]}');

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $this->log);
        $this->assertSame([], $this->received);
        $this->assertNotEmpty($answer['problems']);
    }

    public function testATableThatCannotRollBackIsRefusedBeforeTheFirstStep(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, engines: ['tl_content' => 'MyISAM']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $this->log);
        $this->assertStringContainsString('tl_content (MyISAM)', $answer['message']);
    }

    public function testACommandMissingOnThisInstallationIsRefusedBeforeTheFirstStep(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, without: ['contao:language:link']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $this->log);
        $this->assertSame([], $this->received);
        $this->assertStringContainsString('contao:language:link', $answer['message']);
        $this->assertStringContainsString('terminal42/contao-changelanguage', $answer['message']);
    }

    public function testOnlyTheCommandsThePlanUsesMustExist(): void
    {
        [$code, $answer] = $this->apply('{"steps": [{"do": "page create", "title": "x"}]}', without: ['contao:language:link']);

        $this->assertSame(Command::SUCCESS, $code, json_encode($answer));
    }

    public function testAnExceptionInsideAStepStillRollsBack(): void
    {
        // doRun() does not catch what a command throws; the run must not stay open.
        try {
            $this->apply(self::PLAN, failOn: 'throw:contao:content:create');
        } catch (\RuntimeException) {
            // JsonErrorBoundary answers it in the real container; here it may surface.
        }

        $this->assertSame(['begin', 'rollback'], $this->log);
        $this->assertCount(3, $this->received);
    }

    public function testAPageWithoutAnArticleFailsTheStepThatNeedsIt(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, failOn: 'no-article');

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['begin', 'rollback'], $this->log);
        $this->assertCount(2, $this->received, 'the content create never ran');
        $this->assertSame(2, $answer['failedStep']['index']);
        $this->assertStringContainsString('"@versand.article" has no ID', $answer['failedStep']['message']);
    }

    public function testAnErrorAnswerWithExitCodeZeroStillFailsTheStep(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, failOn: 'quiet:contao:content:create');

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['begin', 'rollback'], $this->log);
        $this->assertSame(2, $answer['failedStep']['index']);
        $this->assertStringContainsString('an error with exit code 0', $answer['failedStep']['message']);
    }

    public function testALinePrintedBeforeTheAnswerDoesNotHideIt(): void
    {
        [$code, $answer] = $this->apply(self::PLAN, failOn: 'chatty');

        $this->assertSame(Command::SUCCESS, $code, json_encode($answer));
        $this->assertSame(['versand' => 100, 'versand.article' => 1000, 'intro' => 102], $answer['refs']);
    }

    public function testASearchTableThatCannotRollBackIsRefused(): void
    {
        // Contao's page callbacks remove search entries when a page is deleted.
        [$code, $answer] = $this->apply(self::PLAN, engines: ['tl_search_index' => 'MyISAM']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $this->log);
        $this->assertStringContainsString('tl_search_index (MyISAM)', $answer['message']);
    }

    public function testStepsRunWithoutInteraction(): void
    {
        $this->apply(self::PLAN);

        $this->assertSame([false], array_values(array_unique(array_column($this->received, 'interactive'))));
    }

    public function testARefOnAStepThatCreatesNothingIsTheRecordItTouched(): void
    {
        $plan = '{"steps": [{"ref": "a", "do": "language link", "table": "tl_page", "id": 5, "main": 6},'
            . ' {"do": "content create", "type": "text", "pid": "@a"}]}';
        [$code, $answer] = $this->apply($plan);

        $this->assertSame(Command::SUCCESS, $code, json_encode($answer));
        $this->assertSame('5', $this->received[1]['options']['pid']);
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, string> $engines
     * @param list<string>          $without stand-ins left out, as on an installation without the package
     *
     * @return array{int, array<string, mixed>}
     */
    private function apply(string $plan, array $options = [], array $engines = [], ?string $failOn = null, array $without = []): array
    {
        $this->log  = $this->received = $this->invalidated = [];
        $this->next = 100;

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn($engines);
        $connection->method('beginTransaction')->willReturnCallback(function (): void { $this->log[] = 'begin'; });
        $connection->method('commit')->willReturnCallback(function (): void { $this->log[] = 'commit'; });
        $connection->method('rollBack')->willReturnCallback(function (): void { $this->log[] = 'rollback'; });
        $connection->method('isTransactionActive')->willReturnCallback(fn (): bool => 'begin' === end($this->log));

        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->method('invalidate')->willReturnCallback(function (array $tags): void { $this->invalidated = $tags; });

        $application = new Application();
        $application->setAutoExit(false);

        foreach (array_diff(self::STAND_INS, $without) as $name) {
            $application->add($this->standIn($name, $failOn));
        }

        $apply = new ApplyCommand($connection, $cacheTags);
        $application->add($apply);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $plan);
        rewind($stream);
        $apply->setStdin($stream);

        $tester = new CommandTester($apply);
        $code   = $tester->execute($options);

        return [$code, json_decode(trim($tester->getDisplay()), true)];
    }

    /**
     * $failOn: `name` answers with an error, `quiet:name` answers with an error but
     * exit code 0, `throw:name` throws, `no-article` makes the page create answer
     * without an article, `chatty` prints a line before every answer.
     */
    private function standIn(string $name, ?string $failOn): Command
    {
        $record = function (array $entry): void { $this->received[] = $entry; };
        $nextId = fn (): int => $this->next++;

        return new class($name, $failOn, $record, $nextId) extends Command {
            public function __construct(string $name, private readonly ?string $failOn, private readonly \Closure $record, private readonly \Closure $nextId)
            {
                parent::__construct($name);
            }

            protected function configure(): void
            {
                $this->addArgument('id', InputArgument::OPTIONAL);
                foreach (['title', 'pid', 'ptable', 'type', 'table', 'main', 'operator'] as $option) {
                    $this->addOption($option, null, InputOption::VALUE_REQUIRED);
                }
                $this->addOption('id', null, InputOption::VALUE_REQUIRED);
                $this->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                ($this->record)(['name' => $this->getName(), 'id' => $input->getArgument('id') ?? $input->getOption('id'), 'options' => $input->getOptions(), 'interactive' => $input->isInteractive()]);

                if ($this->getName() === $this->failOn) {
                    $output->writeln('{"status":"error","message":"refused by the stand-in","code":1}');

                    return Command::FAILURE;
                }

                if ('quiet:' . $this->getName() === $this->failOn) {
                    $output->writeln('{"status":"error","message":"an error with exit code 0","code":1}');

                    return Command::SUCCESS;
                }

                if ('chatty' === $this->failOn) {
                    $output->writeln('A notice printed before the answer');
                }

                if ('throw:' . $this->getName() === $this->failOn) {
                    throw new \RuntimeException('the stand-in broke');
                }

                $id     = ($this->nextId)();
                $answer = ['status' => 'ok', 'id' => $id, 'cacheTags' => ['contao.db.x.' . $id]];
                if ('contao:page:create' === $this->getName() && 'no-article' !== $this->failOn) {
                    $answer['articles'] = [['id' => 900 + $id]];
                }
                $output->writeln(json_encode($answer));

                return Command::SUCCESS;
            }
        };
    }
}
