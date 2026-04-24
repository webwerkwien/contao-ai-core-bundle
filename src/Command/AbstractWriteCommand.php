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
        $this->versionManager->createVersion($table, $id);
    }

    protected function outputSuccess(array $data): void
    {
        $this->logger->info('contao-ai-core-bundle audit', [
            'command'  => $this->getName(),
            'user'     => $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent',
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
