<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Monolog\ContaoContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractReader;
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
 * Both, deliberately (decision 2026-09-01), because they have different readers
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
            'Acting user identifier for the log entry.',
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

        // The namespace is checked first and the contract only when it fails,
        // so the common case costs nothing — resolving a contract means
        // building the command service.
        //
        // A declared #[AiContract] opens the door because that permission comes
        // from the *author*, which is the one thing a prefix cannot say. See
        // AiRunGuard for why the namespace alone was the wrong measure.
        if (!AiRunGuard::isAllowed($name)) {
            $command  = $application->find($name);
            $declared = null !== ContractReader::read(
                $command instanceof LazyCommand ? $command->getCommand()::class : $command::class,
            );

            if (!$declared) {
                return $this->outputError(AiRunGuard::refusal($name));
            }
        }

        // Before the run, on purpose — see the class docblock.
        $this->recordInvocation($line);

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
        $exitCode = $application->doRun(new StringInput($line), $this->output);

        return Command::SUCCESS === $exitCode ? Command::SUCCESS : Command::FAILURE;
    }

    private function recordInvocation(string $line): void
    {
        if (null === $this->systemLog) {
            return;
        }

        $operator = (string) ($this->input->getOption('operator') ?: ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent'));

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
