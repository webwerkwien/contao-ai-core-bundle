<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractPresenter;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractReader;

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
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

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
            // Describing is not running. The guard bounds what this tool
            // executes on its own — its own docblock says so — and refusing to
            // *read* a definition bought no safety: whoever calls this has
            // shell access either way. What it did buy was a dead end, because
            // the listing named commands that could then not be looked at.
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

            // Read, not instantiated — see ContractReader. An extension can
            // declare against this bundle without depending on it, so the
            // absence of a contract is the normal case and not a fault.
            $contract = ContractReader::read(self::declaringClass($command));
            $reachable = AiRunGuard::isAllowed($wanted, null !== $contract);

            // Only when a contract actually names tables. Booting the framework
            // is not free, and the listing half of this command has never
            // needed it — paying for it on every call to describe a command
            // that declares nothing would be a cost with no reader.
            if (null !== $contract && [] !== ($contract['fields']['tables'] ?? [])) {
                $this->framework->initialize();
            }

            $this->outputRecord([
                'command'     => $wanted,
                'description' => $command->getDescription(),
                'help'        => $command->getHelp(),
                'reachable'   => $reachable,
                'reachable_note' => $reachable ? null : AiRunGuard::refusal($wanted),
                'contract'    => null === $contract
                    ? null
                    : ContractPresenter::present($contract, self::tableHasDca(...)),
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
            if ($command->isHidden()) {
                continue;
            }

            $commands[] = [
                'name'        => $name,
                'description' => $command->getDescription(),
                // Listed even when out of reach, and this is a correction:
                // filtering them out made `ext list` answer "available: 0"
                // on an installation that had 87 commands it could not reach —
                // through the very command built to report what it cannot
                // reach. Set aside, never hidden; the same rule the three
                // infrastructure entries already follow.
                // The contract is only resolved for names outside `contao:`,
                // because that is the only place it can change the answer —
                // and resolving one means building the command service, which
                // a listing has no business doing 200 times over.
                'reachable'   => AiRunGuard::isAllowed($name)
                    || (null !== ContractReader::read(self::declaringClass($command))),
            ];
        }

        usort($commands, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $this->outputRecord([
            'count'       => \count($commands),
            'reachable'   => \count(array_filter($commands, static fn (array $c): bool => $c['reachable'])),
            'commands'    => $commands,
        ]);

        return Command::SUCCESS;
    }

    /**
     * The class that actually carries the attributes.
     *
     * Symfony wraps every container-registered command in a `LazyCommand` so
     * the service is not built until it runs. `$command::class` therefore
     * answers `LazyCommand`, which declares no contract — and the manifest
     * reported `contract: null` for a plugin that had declared a full one.
     *
     * 🎯 Worth naming, because the failure wore the usual disguise: `null` is a
     * valid answer here, and it reads as "this command declares nothing"
     * rather than "we looked in the wrong place". Found on c5 within minutes of
     * the first live run, by checking the attribute directly instead of
     * trusting the empty result.
     */
    private static function declaringClass(Command $command): string
    {
        return $command instanceof LazyCommand
            ? $command->getCommand()::class
            : $command::class;
    }

    /**
     * Does this installation have a DCA for the table?
     *
     * The one part of a declared contract that can be held against the site
     * right here. A named table without a DCA is a typo or an extension that
     * is not installed, and both are worth knowing before the command runs.
     */
    private static function tableHasDca(string $table): bool
    {
        if (!class_exists(Controller::class)) {
            return false;
        }

        // The framework has to be up before this runs — `loadDataContainer`
        // reaches for the container and throws otherwise. The first live run
        // on c5 died exactly there, and the JSON error it produced was read as
        // "the plugin declares nothing" because the reading script asked for a
        // key that an error payload does not have.
        Controller::loadDataContainer($table);

        return isset($GLOBALS['TL_DCA'][$table]);
    }

    /**
     * Options Symfony adds to every command; listing them is noise.
     */
    private const FRAMEWORK_OPTIONS = [
        'help', 'silent', 'quiet', 'verbose', 'version',
        'ansi', 'no-ansi', 'no-interaction', 'env', 'profile',
    ];
}
