<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The `--operator` option for commands that do not extend AbstractWriteCommand.
 *
 * Attribution matters more since v0.2.11, because the operator is what ends up
 * in `tl_log.username`. Without the option a command can only ever report the
 * shell user, even when contao-ai-backend-bundle is running it on behalf of a
 * named back-end user — and that layer only passes `--operator` to commands
 * whose definition declares it (`AbstractCoreCommandTool::runCommand()`), so a
 * missing option silently costs the attribution rather than erroring.
 */
trait OperatorOptionTrait
{
    protected function addOperatorOption(): void
    {
        $this->addOption(
            'operator', null,
            InputOption::VALUE_REQUIRED,
            'Acting user identifier for the audit log. Backend integrations pass the '
            . 'Contao username here so audit/version rows attribute changes correctly. '
            . 'When omitted, falls back to $_SERVER[USER] (CLI operator).',
            ''
        );
    }

    protected function resolveOperatorName(InputInterface $input): string
    {
        $explicit = (string) ($input->getOption('operator') ?? '');

        if ('' !== $explicit) {
            return $explicit;
        }

        return (string) ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent');
    }
}
