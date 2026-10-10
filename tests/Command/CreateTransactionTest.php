<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\CreatesRecord;

/**
 * A create runs in one transaction, so a refusing oncreate/onsubmit callback leaves
 * no record behind (v1.4.0). Review M1: nothing tested execute()'s transaction.
 *
 * The commands below run through the real execute(); only the work inside is a stand-in.
 */
class CreateTransactionTest extends TestCase
{
    /** @var list<string> */
    public static array $log = [];

    protected function setUp(): void
    {
        self::$log = [];
    }

    private function transaction(int $expected): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly($expected))->method('transactional')->willReturnCallback(static function (callable $fn) {
            self::$log[] = 'begin';

            try {
                $result = $fn();
            } catch (\Throwable $e) {
                self::$log[] = 'rollback';

                throw $e;
            }

            self::$log[] = 'commit';

            return $result;
        });

        return $connection;
    }

    public function testACreateThatACallbackRefusesIsRolledBackAndAnsweredAsARefusal(): void
    {
        $command = new TransactionProbeCreateCommand();
        $command->setTransactionConnection($this->transaction(1));

        $tester = new CommandTester($command);
        $code   = $tester->execute(['--set' => ['title=Neu']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['begin', 'insert', 'rollback'], self::$log);

        $answer = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('error', $answer['status']);
        $this->assertStringContainsString('refused by Probe::check', $answer['message']);
        $this->assertArrayNotHasKey('exception', $answer, 'a refusal, not a defect');
    }

    public function testAnUpdateRunsWithoutTheOuterTransaction(): void
    {
        // An update of many records keeps the ones that succeeded.
        $command = new TransactionProbeUpdateCommand();
        $command->setTransactionConnection($this->transaction(0));

        (new CommandTester($command))->execute([]);

        $this->assertSame(['update'], self::$log);
    }
}

final class TransactionProbeCreateCommand extends AbstractWriteCommand implements CreatesRecord
{
    protected function configure(): void
    {
        $this->setName('test:probe:create');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        CreateTransactionTest::$log[] = 'insert';

        throw new \InvalidArgumentException('tl_page.oncreate refused by Probe::check. Nothing was written.');
    }
}

final class TransactionProbeUpdateCommand extends AbstractWriteCommand
{
    protected function configure(): void
    {
        $this->setName('test:probe:update');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        CreateTransactionTest::$log[] = 'update';

        return Command::SUCCESS;
    }
}
