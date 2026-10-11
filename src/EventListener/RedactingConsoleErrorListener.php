<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\EventListener\ErrorListener;
use Webwerkwien\ContaoAiCoreBundle\Command\AiRunCommand;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractReader;
use Webwerkwien\ContaoAiCoreBundle\Service\CommandLineRedactor;

/**
 * Symfony's console error listener, with the secrets blanked from the command line (v1.6.0).
 *
 * v1.5.0 kept `--set password=…` out of `tl_log`. The same line went on to a second
 * log: when a command fails, Symfony's `console.error_listener` writes it to
 * `var/logs/prod-*.log` as CRITICAL — *Error thrown while running command "…"* — and a
 * failed exit as debug, which the fingers-crossed handler flushes with the error.
 * Both in plain text, on every Contao installation, for every command.
 *
 * This class takes the place of Symfony's (see RedactingConsoleErrorListenerPass): the
 * same messages, the same channel and priority, the line passed through
 * {@see CommandLineRedactor} first — with the secrets the failing command declares in
 * its #[AiContract], and for `contao:ai:run` those of the command it runs.
 *
 * The error message is struck as well, and the exception in the log context replaced
 * by a {@see RedactedConsoleError} where one of its messages quotes a blanked value:
 * Symfony's own *No arguments expected, got "password=…"* does — a `--set` forgotten
 * (found on c5 while checking this listener). Only values found on the line are
 * struck: a command that puts some other secret into its exception text puts it
 * into the log.
 */
class RedactingConsoleErrorListener extends ErrorListener
{
    public function __construct(private readonly ?LoggerInterface $redactingLogger = null)
    {
        parent::__construct($redactingLogger);
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        if (null === $this->redactingLogger) {
            return;
        }

        [$inputString, $values] = self::input($event);
        $strike                 = self::striker($values);

        $error   = $event->getError();
        $message = $strike($error->getMessage());

        if (self::quotesASecret($error, $strike)) {
            $error = RedactedConsoleError::of($error, $strike);
        }

        if (!$inputString) {
            $this->redactingLogger->critical('An error occurred while using the console. Message: "{message}"', ['exception' => $error, 'message' => $message]);

            return;
        }

        $this->redactingLogger->critical('Error thrown while running command "{command}". Message: "{message}"', ['exception' => $error, 'command' => $inputString, 'message' => $message]);
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (null === $this->redactingLogger) {
            return;
        }

        $exitCode = $event->getExitCode();

        if (0 === $exitCode) {
            return;
        }

        if (!$inputString = self::input($event)[0]) {
            $this->redactingLogger->debug('The console exited with code "{code}"', ['code' => $exitCode]);

            return;
        }

        $this->redactingLogger->debug('Command "{command}" exited with code "{code}"', ['command' => $inputString, 'code' => $exitCode]);
    }

    /**
     * The line as Symfony's listener builds it (its method is private), blanked, and
     * the values blanked in it — null when its arguments were withheld.
     *
     * @return array{?string, list<string>|null}
     */
    private static function input(ConsoleEvent $event): array
    {
        $command     = $event->getCommand();
        $commandName = $command?->getName();
        $input       = $event->getInput();

        if (!$input instanceof \Stringable) {
            return [$commandName, []];
        }

        $line = (string) $input;

        if ($commandName) {
            $line = str_replace(["'$commandName'", "\"$commandName\""], $commandName, $line);
        }

        return CommandLineRedactor::redacted($line, self::declaredSecrets($event, $command));
    }

    /**
     * What strikes the blanked values from a message. Longest first, so a value that
     * contains another goes whole. Where the arguments were withheld nothing says what
     * a message might quote, and every message is withheld as well.
     *
     * @param list<string>|null $values
     *
     * @return \Closure(string): string
     */
    private static function striker(?array $values): \Closure
    {
        if (null === $values) {
            return static fn (string $message): string => '' === $message ? '' : self::MESSAGE_WITHHELD;
        }

        $values = array_filter($values, static fn (string $value): bool => '' !== $value);
        usort($values, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        // A value of one or two characters (`--token 7`) only as a word of its own:
        // struck everywhere, it would blank every 7 in the message.
        $patterns = array_map(
            static fn (string $value): string => \strlen($value) < 3
                ? '/(?<![\w])' . preg_quote($value, '/') . '(?![\w])/u'
                : '/' . preg_quote($value, '/') . '/u',
            $values,
        );

        return static function (string $message) use ($patterns): string {
            if ([] === $patterns) {
                return $message;
            }

            // A message that is no valid UTF-8 fails the patterns; it is withheld then.
            return preg_replace($patterns, CommandLineRedactor::MASK, $message) ?? self::MESSAGE_WITHHELD;
        };
    }

    public const MESSAGE_WITHHELD = '[message withheld: the command line could not be checked for secrets]';

    /**
     * @param \Closure(string): string $strike
     */
    private static function quotesASecret(\Throwable $error, \Closure $strike): bool
    {
        for ($e = $error; null !== $e; $e = $e->getPrevious()) {
            if ($strike($e->getMessage()) !== $e->getMessage()) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the command declares secret, and for `contao:ai:run` the command it runs.
     * Never fails: a log entry is written in any case, at worst blanked by name only.
     *
     * @return list<string>
     */
    private static function declaredSecrets(ConsoleEvent $event, ?Command $command): array
    {
        if (null === $command) {
            return [];
        }

        try {
            $secrets = self::secretsOf($command);
            $real    = $command instanceof LazyCommand ? $command->getCommand() : $command;

            if ($real instanceof AiRunCommand && null !== ($application = $command->getApplication())) {
                $input = $event->getInput();
                $line  = $input->hasOption('command-line') ? trim((string) $input->getOption('command-line')) : '';
                $name  = '' === $line ? false : strtok($line, " \t");

                if (false !== $name && $application->has($name)) {
                    $secrets = [...$secrets, ...self::secretsOf($application->find($name))];
                }
            }

            return $secrets;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private static function secretsOf(Command $command): array
    {
        $class   = $command instanceof LazyCommand ? $command->getCommand()::class : $command::class;
        $secrets = ContractReader::read($class)['fields']['secrets'] ?? [];

        return \is_array($secrets) ? array_values(array_map('strval', $secrets)) : [];
    }
}
