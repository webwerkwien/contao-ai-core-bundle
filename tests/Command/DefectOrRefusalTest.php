<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\EntityClonerInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * v1.3.0: the two places outside JsonErrorBoundary that catch \Throwable and answer
 * for it themselves mark a defect the same way — `exception` for anything that is
 * not the \InvalidArgumentException this bundle refuses with.
 *
 * The chat of the backend bundle turns that field into a bug report; without it a
 * failed transaction in a clone read like "Seite 9 nicht gefunden".
 */
class DefectOrRefusalTest extends TestCase
{
    private function clone(\Throwable $thrown): array
    {
        $cloner = $this->createMock(EntityClonerInterface::class);
        $cloner->method('supports')->willReturn(true);
        $cloner->method('clone')->willThrowException($thrown);

        $tester = new CommandTester(new RecordCloneCommand([$cloner]));
        $tester->execute(['--source-table' => 'tl_page', '--source-id' => '1']);

        return json_decode($tester->getDisplay(), true);
    }

    public function testAMissingCloneSourceIsARefusal(): void
    {
        $answer = $this->clone(new \InvalidArgumentException('Page 1 nicht gefunden.'));

        $this->assertSame('error', $answer['status']);
        $this->assertSame('Page 1 nicht gefunden.', $answer['message']);
        $this->assertArrayNotHasKey('exception', $answer);
    }

    public function testAFailedCloneTransactionIsADefect(): void
    {
        $answer = $this->clone(new \RuntimeException('Deadlock found when trying to get lock'));

        $this->assertSame('error', $answer['status']);
        $this->assertSame('RuntimeException', $answer['exception']);
    }

    /**
     * Pre-release review 2026-10-10: only the row copier had a test. Turning a cloner
     * back to \RuntimeException left the whole suite green — and "Page 9 nicht
     * gefunden" would reach the chat as a crash with a bug report.
     */
    public function testEveryCloneSourceNotFoundIsThrownAsARefusal(): void
    {
        $found = [];
        foreach (glob(__DIR__ . '/../../src/Service/Cloner/*.php') ?: [] as $file) {
            preg_match_all('/throw new \\\\(\w+)\(\\\\sprintf\(\'([^\']*)\'/', (string) file_get_contents($file), $m, PREG_SET_ORDER);
            foreach ($m as [, $class, $message]) {
                $found[basename($file) . ': ' . $message] = $class;
            }
        }

        $notFound = array_filter($found, static fn (string $key): bool => str_contains($key, 'nicht gefunden'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(5, $notFound, 'the five clone sources the scan has to see');
        $this->assertSame(array_fill_keys(array_keys($notFound), 'InvalidArgumentException'), $notFound);

        // The known non-match: a page that vanishes inside the clone is a defect.
        $this->assertSame('RuntimeException', $found['PageCloner.php: Cloned page %d vanished before its alias could be saved.'] ?? null);
    }

    /**
     * Symfony extends \InvalidArgumentException for programming errors — an option
     * name with a typo, a missing service. Those are defects, not refusals.
     */
    public function testAFrameworkSubclassOfInvalidArgumentExceptionIsADefect(): void
    {
        $answer = $this->clone(new \Symfony\Component\Console\Exception\InvalidArgumentException('The "tippfehler" option does not exist.'));

        $this->assertSame('InvalidArgumentException', $answer['exception']);
    }

    /**
     * Second pre-release review: a typo in the caller's command line reached the
     * boundary as Symfony's Console RuntimeException and was marked a defect.
     */
    public function testATypoInAnAiRunCommandLineIsARefusal(): void
    {
        $answer = $this->aiRun('contao:probe --idd=1');

        $this->assertSame('error', $answer['status']);
        $this->assertStringContainsString('"--idd" option does not exist', $answer['message']);
        $this->assertArrayNotHasKey('exception', $answer);
    }

    public function testACrashInsideTheTargetOfAiRunStaysADefect(): void
    {
        $answer = $this->aiRun('contao:probe --id=0');

        $this->assertSame('LogicException', $answer['exception']);
    }

    private function aiRun(string $line): array
    {
        $target = new class extends \Symfony\Component\Console\Command\Command {
            protected function configure(): void
            {
                $this->setName('contao:probe')->addOption('id', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED);
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                throw new \LogicException('a bug in the target');
            }
        };

        $application = new \Symfony\Component\Console\Application();
        $application->setAutoExit(false);
        $application->add($target);
        $application->add($command = new \Webwerkwien\ContaoAiCoreBundle\Command\AiRunCommand());

        $tester = new CommandTester($command);
        $tester->execute(['--command-line' => $line]);

        return json_decode(trim($tester->getDisplay()), true);
    }

    public function testEachRecordOfABulkUpdateSaysWhichKindItsFailureWas(): void
    {
        $command = new class ($this->createMock(ContaoFramework::class)) extends PageUpdateCommand {
            protected function applyToRecord(int $id, array $fields): ?array
            {
                return match ($id) {
                    1       => throw new \InvalidArgumentException('A website root belongs at the top level.'),
                    2       => throw new \TypeError('Argument #1 must be of type int'),
                    default => null,
                };
            }
        };
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));

        $tester = new CommandTester($command);
        $tester->execute(['--ids' => '1,2,3', '--set' => ['title=x']]);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame('partial', $answer['status']);
        $errors = array_column($answer['errors'], null, 'id');
        $this->assertArrayNotHasKey('exception', $errors[1], 'a refusal');
        $this->assertSame('TypeError', $errors[2]['exception']);
        $this->assertArrayNotHasKey('exception', $errors[3], 'not found is a refusal too');
    }
}
