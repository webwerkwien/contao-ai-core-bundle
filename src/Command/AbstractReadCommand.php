<?php declare(strict_types=1);

declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractReadCommand extends Command
{
    protected InputInterface $input;
    protected OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input  = $input;
        $this->output = $output;

        return $this->doExecute();
    }

    abstract protected function doExecute(): int;

    protected function outputRecord(array $data): void
    {
        $this->output->writeln(json_encode(
            ['status' => 'ok'] + $data,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
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
