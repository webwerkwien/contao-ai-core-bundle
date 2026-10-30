<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiCoreBundle\Command\AiRunCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

/**
 * The log entry contao:ai:run writes before the run carries no secret (v1.5.0).
 * Through the real command and a real console application; only tl_log is a stand-in.
 */
class AiRunLogRedactionTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    private function aiRun(string $line): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->add(new class extends Command {
            protected function configure(): void
            {
                $this->setName('contao:probe-set')
                    ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
                    ->addOption('api-key', null, InputOption::VALUE_REQUIRED)
                    // as Contao's contao:user:password declares it
                    ->addOption('password', 'p', InputOption::VALUE_REQUIRED);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        });
        $application->add(new AcmePinCommand());

        $log = $this->createMock(SystemLog::class);
        $log->method('write')->willReturnCallback(function (string $message): void {
            $this->logged[] = $message;
        });

        $application->add($command = new AiRunCommand());
        $command->setSystemLog($log);

        (new CommandTester($command))->execute(['--command-line' => $line]);
    }

    public function testANameThatReadsLikeASecretIsBlankedInTheLog(): void
    {
        $this->aiRun('contao:probe-set --set password=geheim --set title=Laden --api-key=abc');

        $this->assertSame(['contao:ai:run started an unwrapped command: contao:probe-set --set password=*** --set title=Laden --api-key=***'], $this->logged);
    }

    public function testTheShortcutOfASecretOptionIsBlankedInTheLog(): void
    {
        $this->aiRun('contao:probe-set -p geheim --set title=Laden');

        $this->assertSame(['contao:ai:run started an unwrapped command: contao:probe-set -p *** --set title=Laden'], $this->logged);
    }

    public function testWhatTheCommandDeclaresSecretIsBlankedToo(): void
    {
        // acme: is no allowed namespace; the contract opens the door and names the secret.
        $this->aiRun('acme:pin --pin 4711 --set iban=AT61 --set title=x');

        $this->assertSame(['contao:ai:run started an unwrapped command: acme:pin --pin *** --set iban=*** --set title=x'], $this->logged);
    }
}

#[AiContract(writes: false, secrets: ['pin', 'iban'])]
final class AcmePinCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('acme:pin')
            ->addOption('pin', null, InputOption::VALUE_REQUIRED)
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
