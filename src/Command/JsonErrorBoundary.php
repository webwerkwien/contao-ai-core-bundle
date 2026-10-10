<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turn an uncaught exception into the same JSON error every other failure uses.
 *
 * Every deliberate failure in this bundle answers
 * `{"status":"error","message":…,"code":1}` and exits 1. An exception that got
 * past a command did something else entirely: a PHP stack trace on stdout and
 * exit 255. The caller is a script or an agent parsing JSON, so that is not a
 * worse message — it is *no* message, in a shape nothing here expects.
 *
 * Found through issue #24, where `--set addFile=` reached a boolean column and
 * DBAL threw. That input is fixed at the source; this is the floor underneath
 * it, because the next unexpected exception should not be discovered the same
 * way.
 *
 * ## Why it catches everything, and why that is not too much
 *
 * `\Throwable`, not just DBAL. A caller cannot act differently on a TypeError
 * than on a DriverException — both mean "this command did not do the thing" —
 * and a boundary that lists the exceptions it knows about only holds until the
 * next one nobody listed.
 *
 * 🎯 **The objection to catching everything is real: it hides the stack trace
 * you want while developing.** So the boundary has a vent. At `-vvv`
 * (`VERBOSITY_DEBUG`) the exception is rethrown untouched and Symfony renders
 * it as before. Nothing in the CLI passes `-vvv`, so the vent costs the normal
 * path nothing and is there for whoever is on the server with a real problem.
 *
 * ## Exit code
 *
 * 1, the same as every other error here — not 255. A caller that has to tell a
 * database error from a usage error reads the message; the exit code answers
 * one question, "did it work", and two failure codes for one answer is a
 * distinction nobody asked for.
 *
 * ## The message keeps the exception class
 *
 * `DriverException: An exception occurred while executing a query: …` rather
 * than the message alone. The class name is the one piece that says which
 * *layer* failed, and it is free.
 *
 * ## A defect says so: `exception` (v1.3.0)
 *
 * The boundary catches two different things: a refusal the bundle throws on
 * purpose ("a website root belongs at the top level") and a defect nobody
 * planned for (DBAL, a TypeError). Both answered alike, so a caller had to treat
 * a refusal like a crash or a crash like a refusal — the chat of the backend
 * bundle showed both as a refusal and never offered a bug report.
 *
 * The convention this rests on: **refusals are `\InvalidArgumentException`.**
 * Every deliberate one in this bundle is; the clone sources that are not found
 * were `\RuntimeException` until v1.3.0 and were changed to match. Anything else
 * gets `"exception": "<ShortClassName>"` added. `code` and the exit stay 1 —
 * one answer to "did it work", and a field nobody reads breaks nobody.
 */
trait JsonErrorBoundary
{
    /**
     * Run $work; answer a JSON error instead of letting a throwable escape.
     *
     * @param callable(): int $work
     */
    protected function guarded(OutputInterface $output, callable $work): int
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            // The vent: -vvv wants the trace, not a tidy summary.
            if ($output->isDebug()) {
                throw $e;
            }

            $answer = [
                'status'  => 'error',
                'message' => \sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()),
                'code'    => 1,
            ] + self::defectOf($e);

            $output->writeln(json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return Command::FAILURE;
        }
    }

    /**
     * `['exception' => <ShortClassName>]` for a defect, `[]` for a refusal.
     *
     * A refusal is thrown as \InvalidArgumentException throughout this bundle (the
     * page tree rules, input checks, a missing clone source). Shared with the two
     * places that catch \Throwable themselves and answer for it: a clone, and each
     * record of a bulk update.
     *
     * @return array{exception?: string}
     */
    protected static function defectOf(\Throwable $e): array
    {
        return $e instanceof \InvalidArgumentException ? [] : ['exception' => (new \ReflectionClass($e))->getShortName()];
    }
}
