<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Monolog\ContaoContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractReader;
use Webwerkwien\ContaoAiCoreBundle\Service\CommandLineRedactor;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

/**
 * Run a `contao:*` command this bundle does not wrap — and leave a trace that
 * it happened.
 *
 * An extension registers its own console command and the CLI has no wrapper for
 * it. Refusing outright would make every such extension unreachable; wrapping
 * blindly is not possible, because nothing here knows what the command means.
 * So it runs, and the fact that it ran is recorded.
 *
 * ## Why the log entry is written before the command, not after
 *
 * Every write this bundle performs leaves `tl_version`, `tl_log` with
 * `source = CLI`, and `tl_undo` behind. A foreign command has **none of that
 * guaranteed** — it may write without leaving any trace at all.
 *
 * 🎯 **So the one thing that can be promised is the smaller one: that the
 * *invocation* is on record.** Written first, so a command that crashes
 * halfway, times out, or takes the process down with it still leaves the entry
 * that says what was started. A log written afterwards records only the runs
 * that went well, which is the opposite of what an audit trail is for.
 *
 * It says what was started, not what happened. That distinction is in the entry
 * itself rather than left for a reader to work out.
 *
 * ## The warning goes to the caller, the log entry to whoever asks later
 *
 * Both, deliberately, because they have different readers
 * at different times: the warning reaches the caller before the effect, the log
 * entry reaches whoever is reconstructing events afterwards. Either alone
 * leaves one of the two without an answer.
 *
 * ## In-process, not a second connection
 *
 * The target runs through the same Application, so this is one SSH round trip
 * rather than two — and, more to the point, the log entry and the run cannot
 * end up on different sides of a dropped connection.
 */
#[AsCommand(name: 'contao:ai:run', description: 'Run an unwrapped contao:* command and record that it was started')]
class AiRunCommand extends AbstractReadCommand
{
    private ?SystemLog $systemLog = null;

    #[Required]
    public function setSystemLog(SystemLog $systemLog): void
    {
        $this->systemLog = $systemLog;
    }

    protected function configure(): void
    {
        $this->addOption(
            'command-line', null, InputOption::VALUE_REQUIRED,
            'The command to run, with its arguments, e.g. "contao:ai:commands --name=contao:migrate"',
            '',
        );
        $this->addOption(
            'operator', null, InputOption::VALUE_REQUIRED,
            'Acting user: written to the log entry, and handed on to a command that has an --operator option of its own.',
            '',
        );
    }

    protected function doExecute(): int
    {
        $line = trim((string) $this->input->getOption('command-line'));

        if ('' === $line) {
            return $this->outputError('--command-line is required');
        }

        $name = strtok($line, " \t") ?: $line;

        $application = $this->getApplication();

        if (null === $application) {
            return $this->outputError('No console application available');
        }

        if (!$application->has($name)) {
            return $this->outputError('Command not found: '.$name);
        }

        // A declared #[AiContract] opens the door because that permission comes
        // from the *author*, which is the one thing a prefix cannot say. See
        // AiRunGuard for why the namespace alone was the wrong measure. Read for
        // every run since v1.5.0: it also names the secrets to keep out of the log.
        // Reading is reflection on the class; the command service is built once
        // either way (bindingRefusal() needs it).
        $target   = $application->find($name);
        $class    = $target instanceof LazyCommand ? $target->getCommand()::class : $target::class;
        $contract = ContractReader::read($class);

        if (!AiRunGuard::isAllowed($name) && null === $contract) {
            return $this->outputError(AiRunGuard::refusal($name));
        }

        // A command line that does not bind — an unknown option, a missing
        // argument — is the caller's mistake, refused before anything runs
        // (v1.3.1). Inside doRun() Symfony's console error listener would first
        // log it as CRITICAL, and a typo is not a critical event. The check
        // mirrors Command::run(), including a target that ignores binding errors.
        $refusal = $this->bindingRefusal($target, $line);
        if (null !== $refusal) {
            return $this->outputError($refusal);
        }

        // Who acts reaches the command, not only this log entry (v1.7.1: a command
        // that records the user fell back to the shell user).
        $line = $this->withOperator($target, $line, $name);

        // Before the run, on purpose — see the class docblock. Without the secrets:
        // the entry stays in tl_log for the log period and in every backup (v1.5.0).
        // A malformed `secrets` is left out by the reader (it reports a problem);
        // the names reading like a secret are blanked regardless.
        $secrets = $contract['fields']['secrets'] ?? [];
        $this->recordInvocation(CommandLineRedactor::redact($line, \is_array($secrets) ? array_values(array_map('strval', $secrets)) : []));

        // `doRun`, with the command name still in the string, rather than
        // `find()->run()` on the remainder. Running the command object directly
        // binds the input against a definition that still carries the implicit
        // `command` argument, so the first real argument lands there and the
        // command reports the next one missing: `contao:search:query Kontakt`
        // answered *Not enough arguments (missing: "query")*.
        //
        // `doRun` is what the console itself does — it resolves the name and
        // binds the rest. It also does not swallow exceptions the way `run()`
        // does, which leaves them to JsonErrorBoundary instead of a stack trace.
        //
        // The target writes to the same output. Its shape is its own: this
        // command promises to run it and to have said so, not to normalise what
        // it answers.
        //
        // A command line that does not bind — an unknown option, a missing
        // argument — is the caller's mistake: a refusal (v1.3.0), not a defect,
        // which JsonErrorBoundary would mark it as. Only binding: Symfony throws
        // its Console RuntimeException there; one of its InvalidArgumentException
        // subclasses out of the target's own code stays a defect.
        try {
            $exitCode = $application->doRun(new StringInput($line), $this->output);
        } catch (ConsoleRuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        return Command::SUCCESS === $exitCode ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Why $line would not bind to $target, or null when Command::run() would take it.
     *
     * Mirrors run(): a binding error counts unless the target ignores validation
     * errors — which suppresses only that one — and validate() runs either way
     * Nothing foreign runs in here, so every
     * Console exception is the line's fault.
     * A LazyCommand is unwrapped: it is what find() returns for a container
     * command, and its own flag is always false.
     */
    private function bindingRefusal(Command $target, string $line): ?string
    {
        $real = $target instanceof LazyCommand ? $target->getCommand() : $target;

        try {
            $real->mergeApplicationDefinition();
            $input = new StringInput($line);

            try {
                $input->bind($real->getDefinition());
            } catch (ConsoleExceptionInterface $e) {
                if (!self::ignoresValidationErrors($real)) {
                    throw $e;
                }
            }

            $input->validate();
        } catch (ConsoleExceptionInterface $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * $line with this command's --operator handed on, when the target defines an
     * `operator` option that takes a value. A refusal, before anything is logged or
     * runs, when the line names another user or the name cannot be passed unchanged.
     *
     * Until v1.7.1 the operator went into the log entry only, and a command with its own
     * `--operator` (Consho's write commands) recorded the shell user. Placed right after
     * the name: the arguments may stand behind `--`, where an option is an argument.
     * Written like the backend bundle's ExtTool writes options: its own token, or the `=`
     * form when the value starts with `-` (it would be read as the next option).
     */
    private function withOperator(Command $target, string $line, string $name): string
    {
        $operator = $this->operator();
        $real     = $target instanceof LazyCommand ? $target->getCommand() : $target;

        if ('' === $operator) {
            return $line;
        }

        // Idempotent; without it the name binds as the first argument and nothing is found.
        $real->mergeApplicationDefinition();
        $definition = $real->getDefinition();

        if (!$definition->hasOption('operator') || !$definition->getOption('operator')->acceptValue()) {
            return $line;
        }

        $option = $definition->getOption('operator');
        $input  = new StringInput($line);

        try {
            $input->bind($definition);
        } catch (ConsoleExceptionInterface) {
            // A target that ignores binding errors (bindingRefusal() let it through).
            // bind() keeps what it parsed before the failing token, and the raw tokens
            // are looked at below for anything behind it.
        }

        // Set in the line at all — in any form, also empty: an empty value written after
        // ours would win (last value wins) and hand the command no user.
        $names = ['--operator'];
        foreach (array_filter(explode('|', (string) $option->getShortcut())) as $shortcut) {
            $names[] = '-'.$shortcut;
        }

        if ($input->hasParameterOption($names, true) || $input->getOption('operator') !== $option->getDefault()) {
            $candidate = $line;
        } else {
            if (str_starts_with($operator, '-') && (str_contains($operator, "''") || str_contains($operator, '"\''))) {
                throw new \InvalidArgumentException('--operator starts with - and contains \'\' or "\' - the console cannot hand that on unchanged.');
            }

            $quoted    = '"'.addcslashes($operator, '"\\').'"';
            $candidate = $name.' '.(str_starts_with($operator, '-') ? '--operator='.$quoted : '--operator '.$quoted).substr($line, \strlen($name));
        }

        // Not the input forms guessed, the outcome checked: the line as it
        // will run, bound the way the command binds it, must hand over exactly this user.
        // That covers what the detection above cannot see — a shortcut inside a cluster
        // (`-xo system`), a value equal to the option's default — and a padded name.
        $received = self::receivedOperator($definition, $candidate);

        if ([] === $received || array_diff($received, [$operator]) !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'The command line sets --operator itself (%s), contao:ai:run was given "%s". Pass it once, as --operator of contao:ai:run.',
                [] === $received ? 'unreadable' : '"'.implode('", "', $received).'"',
                $operator,
            ));
        }

        return $candidate;
    }

    /**
     * What the command would find in its `operator` option for $line — every value, as
     * strings; empty when it would find none. Bound as Command::run() binds: a target that
     * ignores binding errors keeps what was parsed before the failing token.
     *
     * @return list<string>
     */
    private static function receivedOperator(InputDefinition $definition, string $line): array
    {
        $input = new StringInput($line);

        try {
            $input->bind($definition);
        } catch (ConsoleExceptionInterface) {
            // what was parsed before the failing token stays, as in Command::run()
        }

        return array_values(array_map(static fn (mixed $v): string => (string) $v, array_filter((array) $input->getOption('operator'), static fn (mixed $v): bool => null !== $v)));
    }

    /** The run's --operator, the same for the log entry and the command it runs. */
    private function operator(): string
    {
        return trim((string) $this->input->getOption('operator'));
    }

    /**
     * Command keeps the flag private and offers no getter; Symfony's own
     * TraceableCommand reads it the same way. Should the property ever go, this
     * answers false — the strict check v1.3.0 had.
     */
    private static function ignoresValidationErrors(Command $command): bool
    {
        if (!property_exists(Command::class, 'ignoreValidationErrors')) {
            return false;
        }

        return (bool) \Closure::bind(static fn (Command $c): mixed => $c->ignoreValidationErrors, null, Command::class)($command);
    }

    private function recordInvocation(string $line): void
    {
        if (null === $this->systemLog) {
            return;
        }

        $operator = $this->operator();
        if ('' === $operator) {
            $operator = (string) ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent');
        }

        // "started", not "ran": this entry exists before the outcome does, and
        // the wording should not claim more than it knows.
        $this->systemLog->write(
            'contao:ai:run started an unwrapped command: '.$line,
            'contao:ai:run',
            $operator,
            ContaoContext::GENERAL,
        );
    }
}
