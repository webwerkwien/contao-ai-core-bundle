<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;

/**
 * The Contao messages a write's callbacks left reach the caller as `messages` (v1.6.0):
 * with a success, with a refusal thrown inside, and with one answered by outputError().
 * Through the real execute(); only the callbacks are a stand-in.
 */
class CallbackMessagesInAnswerTest extends TestCase
{
    private const MESSAGES = [['type' => 'error', 'text' => 'Alias bereits vergeben.']];

    /**
     * @return array<string, mixed>
     */
    private function answer(string $mode): array
    {
        $callbacks = $this->createMock(DcaCallbacks::class);
        // The first drain forgets an earlier command's messages; the second is this one's.
        $callbacks->method('drainMessages')->willReturnOnConsecutiveCalls([['type' => 'info', 'text' => 'from an earlier command']], self::MESSAGES, []);
        $callbacks->method('drainReport')->willReturn([]);

        $command = new MessagesProbeCommand($mode);
        $command->setDcaCallbacks($callbacks);
        $command->setLogger(new NullLogger());

        $output = new BufferedOutput();
        $command->run(new ArrayInput([]), $output);

        return json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testASuccessCarriesTheMessages(): void
    {
        $answer = $this->answer('ok');

        $this->assertSame('ok', $answer['status']);
        $this->assertSame(self::MESSAGES, $answer['messages']);
    }

    public function testARefusalThrownInsideCarriesThem(): void
    {
        $answer = $this->answer('throw');

        $this->assertSame('error', $answer['status']);
        $this->assertSame('InvalidArgumentException: onsubmit refused', $answer['message']);
        $this->assertSame(self::MESSAGES, $answer['messages']);
    }

    public function testARefusalAnsweredByOutputErrorCarriesThem(): void
    {
        $answer = $this->answer('refuse');

        $this->assertSame('error', $answer['status']);
        $this->assertSame(self::MESSAGES, $answer['messages']);
    }
}

final class MessagesProbeCommand extends AbstractWriteCommand
{
    public function __construct(private readonly string $mode)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('test:probe:messages');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        return match ($this->mode) {
            'throw'  => throw new \InvalidArgumentException('onsubmit refused'),
            'refuse' => $this->outputError('refused'),
            default  => (function (): int {
                $this->outputSuccess(['id' => 7]);

                return self::SUCCESS;
            })(),
        };
    }
}
