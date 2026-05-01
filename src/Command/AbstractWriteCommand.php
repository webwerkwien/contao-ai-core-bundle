<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

abstract class AbstractWriteCommand extends Command
{
    protected InputInterface $input;
    protected OutputInterface $output;
    protected VersionManager $versionManager;
    protected LoggerInterface $logger;

    #[Required]
    public function setVersionManager(VersionManager $versionManager): void
    {
        $this->versionManager = $versionManager;
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this->addOption(
            'set', null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Field=value pairs, e.g. --set email=foo@bar.com',
            []
        );
        $this->addOption(
            'operator', null,
            InputOption::VALUE_REQUIRED,
            'Acting user identifier for the audit log. Backend integrations pass the '
            . 'Contao username here so audit/version rows attribute changes correctly. '
            . 'When omitted, falls back to $_SERVER[USER] (CLI operator).',
            ''
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input  = $input;
        $this->output = $output;

        $fields = $this->parseSetOptions($input->getOption('set'));
        return $this->doExecute($fields);
    }

    abstract protected function doExecute(array $fields): int;

    public function parseSetOptions(array $setOptions): array
    {
        $result = [];
        foreach ($setOptions as $pair) {
            $pos = strpos($pair, '=');
            if ($pos === false) {
                continue;
            }
            $key = substr($pair, 0, $pos);
            $val = substr($pair, $pos + 1);
            if ($key !== '') {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    protected function createVersion(string $table, int $id): void
    {
        $this->versionManager->createVersion($table, $id, $this->resolveOperator());
    }

    /**
     * Operator identifier for audit/version rows. Backend bundle passes the
     * Contao backend user via `--operator`; CLI invocations fall back to the
     * shell user — useful for distinguishing "ssh into prod" vs. "agent action".
     */
    protected function resolveOperator(): string
    {
        $explicit = (string) ($this->input->getOption('operator') ?? '');
        if ('' !== $explicit) {
            return $explicit;
        }
        return (string) ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent');
    }

    /**
     * Resolves the Contao user ID matching the current operator name. Used as
     * `$record->author` on create so audit/byline reflect the actual editor,
     * not the admin (id=1) the framework defaults to.
     *
     * Falls back to id=1 when the operator is empty (CLI), unknown to Contao,
     * or the user lookup fails — keeps existing CLI behaviour intact.
     */
    protected function resolveAuthorId(): int
    {
        $name = $this->resolveOperator();
        if ('' === $name) {
            return 1;
        }
        if (!class_exists(\Contao\UserModel::class)) {
            return 1;
        }
        $user = \Contao\UserModel::findOneBy('username', $name);
        return $user ? (int) $user->id : 1;
    }

    protected function outputSuccess(array $data): void
    {
        $this->logger->info('contao-ai-core-bundle audit', [
            'command'  => $this->getName(),
            'user'     => $this->resolveOperator(),
            'payload'  => $data,
        ]);
        $this->output->writeln(json_encode(['status' => 'ok'] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    protected function outputError(string $message, int $code = 1): int
    {
        $this->output->writeln(json_encode([
            'status'  => 'error',
            'message' => $message,
            'code'    => $code,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return Command::FAILURE;
    }
}
