<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiCoreBundle\Command\JsonErrorBoundary;

/**
 * An exception must leave the same shape every other failure leaves.
 *
 * Deliberate failures answer `{"status":"error","message":…,"code":1}`. An
 * exception that got past a command answered a PHP stack trace on stdout and
 * exit 255 — not a worse message but *no* message, in a shape nothing here
 * expects. The caller is a script or an agent parsing JSON.
 *
 * The coverage test at the bottom is the one that matters most. A boundary that
 * holds for the commands extending a base class and not for the seven that
 * extend Symfony's Command directly is not a boundary, and which is which is
 * invisible from the outside.
 */
class JsonErrorBoundaryTest extends TestCase
{
    private function subject(): object
    {
        return new class {
            use JsonErrorBoundary;

            public function run(OutputInterface $output, callable $work): int
            {
                return $this->guarded($output, $work);
            }
        };
    }

    public function testASuccessfulRunIsPassedThroughUntouched(): void
    {
        $output = new BufferedOutput();

        $this->assertSame(Command::SUCCESS, $this->subject()->run($output, fn (): int => Command::SUCCESS));
        $this->assertSame('', $output->fetch());
    }

    public function testAThrowableBecomesAJsonError(): void
    {
        $output = new BufferedOutput();
        $code   = $this->subject()->run($output, function (): int {
            throw new \RuntimeException('the database said no');
        });

        $this->assertSame(Command::FAILURE, $code);

        $decoded = json_decode(trim($output->fetch()), true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
        $this->assertSame(1, $decoded['code']);
    }

    /**
     * The class name says which layer failed, and it is free. A DriverException
     * and a TypeError read very differently to whoever has to act on them.
     */
    public function testTheMessageKeepsTheExceptionClass(): void
    {
        $output = new BufferedOutput();
        $this->subject()->run($output, function (): int {
            throw new \RuntimeException('the database said no');
        });

        $decoded = json_decode(trim($output->fetch()), true);
        $this->assertSame('RuntimeException: the database said no', $decoded['message']);
    }

    /**
     * Exit 1, not 255 — the same code as every other error here. The exit code
     * answers "did it work"; telling a database error from a usage error is what
     * the message is for.
     */
    public function testTheExitCodeMatchesEveryOtherFailure(): void
    {
        $output = new BufferedOutput();
        $code   = $this->subject()->run($output, function (): int {
            throw new \LogicException('boom');
        });

        $this->assertSame(1, $code);
    }

    /**
     * The vent. Catching everything hides the trace you want while developing,
     * so -vvv gets it back untouched.
     */
    public function testDebugVerbosityRethrowsInstead(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the database said no');

        $this->subject()->run($output, function (): int {
            throw new \RuntimeException('the database said no');
        });
    }

    /**
     * @return list<string> command classes that are outside the boundary
     */
    private function unguarded(): array
    {
        $files = glob(__DIR__ . '/../../src/Command/*Command.php');
        $this->assertIsArray($files);

        $unguarded = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            // The two base classes carry the trait for everything below them.
            if (preg_match('/\bextends\s+Abstract\w*Command\b/', $source)) {
                continue;
            }
            if (str_contains($source, 'use JsonErrorBoundary;')) {
                continue;
            }
            $unguarded[] = basename($file);
        }

        return $unguarded;
    }

    public function testEveryCommandIsInsideTheBoundary(): void
    {
        $this->assertSame([], $this->unguarded(), \sprintf(
            "These commands neither extend a base class that carries JsonErrorBoundary nor\n"
            . "use the trait themselves, so an exception in them still escapes as a stack\n"
            . 'trace and exit 255. Add `use JsonErrorBoundary;` and wrap execute().',
        ));
    }

    /**
     * A scan that finds nothing passes just as quietly as one that finds
     * everything — the lesson this project keeps re-learning.
     */
    public function testTheScanActuallyLooksAtCommands(): void
    {
        $files = glob(__DIR__ . '/../../src/Command/*Command.php');
        $this->assertIsArray($files);
        $this->assertGreaterThanOrEqual(60, \count($files), 'Command files were not found on disk.');
    }
}
