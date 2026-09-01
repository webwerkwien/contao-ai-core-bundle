<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * What this installation's console offers under `contao:`.
 *
 * The CLI wraps a curated set of commands, one Python function each. An
 * extension that ships its own `contao:*` command registers it with Symfony and
 * is then invisible from here: it exists on the server and nothing in the CLI —
 * or in an agent reading `--help` — can learn that it does.
 *
 * Symfony can already describe every command (`list --format=json`, with
 * arguments, options, description and help). What it cannot do is stay small:
 * the full listing on a stock Contao 5.7 is **650 KB**, and shipping that over
 * SSH to answer "what else is here" is the wrong trade. So the filtering
 * happens where the data is.
 *
 * ## What this deliberately does not decide
 *
 * It does not say which commands the CLI already wraps. That knowledge lives in
 * the CLI, changes with the CLI, and would be a second copy here that drifts.
 * The server answers "what exists"; the caller subtracts what it knows.
 *
 * ## Two shapes
 *
 *     contao:ai:commands              → name and description for each
 *     contao:ai:commands --name=x     → the full definition of one
 *
 * The list stays deliberately thin. A caller that wants argument and option
 * details asks for the one command it is actually interested in — the same
 * reason `record:list` has a limit.
 */
#[AsCommand(name: 'contao:ai:commands', description: 'List the contao:* console commands this installation offers')]
class AiCommandsCommand extends AbstractReadCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'name', null, InputOption::VALUE_REQUIRED,
            'Return the full definition of this one command instead of the list',
            '',
        );
    }

    protected function doExecute(): int
    {
        $application = $this->getApplication();

        if (null === $application) {
            return $this->outputError('No console application available');
        }

        $wanted = trim((string) $this->input->getOption('name'));

        if ('' !== $wanted) {
            if (!AiRunGuard::isAllowed($wanted)) {
                return $this->outputError(AiRunGuard::refusal($wanted));
            }
            if (!$application->has($wanted)) {
                return $this->outputError('Command not found: '.$wanted);
            }

            $command    = $application->find($wanted);
            $definition = $command->getDefinition();

            $arguments = [];
            foreach ($definition->getArguments() as $argument) {
                $arguments[$argument->getName()] = [
                    'required'    => $argument->isRequired(),
                    'array'       => $argument->isArray(),
                    'description' => $argument->getDescription(),
                ];
            }

            $options = [];
            foreach ($definition->getOptions() as $option) {
                // The framework's own switches (--env, --quiet, --ansi, …) are on
                // every command and tell a caller nothing about this one.
                if (\in_array($option->getName(), self::FRAMEWORK_OPTIONS, true)) {
                    continue;
                }

                $options[$option->getName()] = [
                    'value_required' => $option->isValueRequired(),
                    'accepts_value'  => $option->acceptValue(),
                    'array'          => $option->isArray(),
                    'description'    => $option->getDescription(),
                ];
            }

            $this->outputRecord([
                'command'     => $wanted,
                'description' => $command->getDescription(),
                'help'        => $command->getHelp(),
                // Cast so an empty set encodes as {} and not []. PHP's empty
                // array is both, json_encode picks the array, and a caller then
                // has to handle two shapes for the same field — found by a
                // reader that did `.items()` on `contao:record:clone`, which
                // takes no arguments.
                'arguments'   => (object) $arguments,
                'options'     => (object) $options,
            ]);

            return Command::SUCCESS;
        }

        $commands = [];
        foreach ($application->all() as $name => $command) {
            if (!AiRunGuard::isAllowed($name) || $command->isHidden()) {
                continue;
            }

            $commands[] = [
                'name'        => $name,
                'description' => $command->getDescription(),
            ];
        }

        usort($commands, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $this->outputRecord([
            'count'    => \count($commands),
            'commands' => $commands,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Options Symfony adds to every command; listing them is noise.
     */
    private const FRAMEWORK_OPTIONS = [
        'help', 'silent', 'quiet', 'verbose', 'version',
        'ansi', 'no-ansi', 'no-interaction', 'env', 'profile',
    ];
}
