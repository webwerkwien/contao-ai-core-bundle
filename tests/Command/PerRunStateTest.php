<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\CreatesRecord;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * A command is a service: in the back-end chat one instance runs several times in a
 * request. The second answer must not carry what the first run left behind (second
 * pre-release review, M-A: `earlierVersions` of the first create in the second's answer).
 */
class PerRunStateTest extends TestCase
{
    public function testTheSecondRunReportsOnlyItsOwnFindings(): void
    {
        $versions = $this->createMock(VersionManager::class);
        $versions->method('createInitialVersion')->willReturnOnConsecutiveCalls(3, 0);

        $command = new PerRunProbeCreateCommand();
        $command->setVersionManager($versions);
        $command->setLogger($this->createMock(\Psr\Log\LoggerInterface::class));

        $tester = new CommandTester($command);

        $tester->execute(['--set' => ['title=A']]);
        $first = json_decode(trim($tester->getDisplay()), true);

        $tester->execute(['--set' => ['title=B']]);
        $second = json_decode(trim($tester->getDisplay()), true);

        $this->assertSame(['tl_probe.7' => 3], $first['earlierVersions']);
        $this->assertSame('alias of run 1', $first['aliasWarning']);
        $this->assertArrayNotHasKey('earlierVersions', $second);
        $this->assertArrayNotHasKey('aliasWarning', $second);
        $this->assertArrayNotHasKey('routeConflicts', $second);
    }

    public function testACacheReportOfARefusedRunDoesNotReachTheNextAnswer(): void
    {
        $cacheTags = $this->createMock(CacheTagInvalidator::class);
        $cacheTags->expects($this->exactly(2))->method('reset');

        $command = new PerRunProbeCreateCommand();
        $command->setVersionManager($this->createMock(VersionManager::class));
        $command->setCacheTagInvalidator($cacheTags);
        $command->setLogger($this->createMock(\Psr\Log\LoggerInterface::class));

        $tester = new CommandTester($command);
        $tester->execute(['--set' => ['title=A']]);
        $tester->execute(['--set' => ['title=B']]);
    }
}

final class PerRunProbeCreateCommand extends AbstractWriteCommand implements CreatesRecord
{
    private int $run = 0;

    protected function configure(): void
    {
        $this->setName('test:probe:per-run');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        ++$this->run;
        $id = 6 + $this->run;

        if (1 === $this->run) {
            (new \ReflectionProperty(AbstractWriteCommand::class, 'aliasWarning'))->setValue($this, 'alias of run 1');
            $this->routeConflicts = [['id' => 1]];
        }

        $this->createVersion('tl_probe', $id, true);
        $this->outputSuccess(['id' => $id]);

        return Command::SUCCESS;
    }
}
