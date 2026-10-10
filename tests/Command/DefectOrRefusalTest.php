<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\EntityClonerInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * v1.3.0: the two places outside JsonErrorBoundary that catch \Throwable and answer
 * for it themselves mark a defect the same way — `exception` for anything that is
 * not the \InvalidArgumentException this bundle refuses with.
 *
 * The chat of the backend bundle turns that field into a bug report; without it a
 * failed transaction in a clone read like "Seite 9 nicht gefunden".
 */
class DefectOrRefusalTest extends TestCase
{
    private function clone(\Throwable $thrown): array
    {
        $cloner = $this->createMock(EntityClonerInterface::class);
        $cloner->method('supports')->willReturn(true);
        $cloner->method('clone')->willThrowException($thrown);

        $tester = new CommandTester(new RecordCloneCommand([$cloner]));
        $tester->execute(['--source-table' => 'tl_page', '--source-id' => '1']);

        return json_decode($tester->getDisplay(), true);
    }

    public function testAMissingCloneSourceIsARefusal(): void
    {
        $answer = $this->clone(new \InvalidArgumentException('Page 1 nicht gefunden.'));

        $this->assertSame('error', $answer['status']);
        $this->assertSame('Page 1 nicht gefunden.', $answer['message']);
        $this->assertArrayNotHasKey('exception', $answer);
    }

    public function testAFailedCloneTransactionIsADefect(): void
    {
        $answer = $this->clone(new \RuntimeException('Deadlock found when trying to get lock'));

        $this->assertSame('error', $answer['status']);
        $this->assertSame('RuntimeException', $answer['exception']);
    }

    public function testEachRecordOfABulkUpdateSaysWhichKindItsFailureWas(): void
    {
        $command = new class ($this->createMock(ContaoFramework::class)) extends PageUpdateCommand {
            protected function applyToRecord(int $id, array $fields): ?array
            {
                return match ($id) {
                    1       => throw new \InvalidArgumentException('A website root belongs at the top level.'),
                    2       => throw new \TypeError('Argument #1 must be of type int'),
                    default => null,
                };
            }
        };
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));

        $tester = new CommandTester($command);
        $tester->execute(['--ids' => '1,2,3', '--set' => ['title=x']]);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame('partial', $answer['status']);
        $errors = array_column($answer['errors'], null, 'id');
        $this->assertArrayNotHasKey('exception', $errors[1], 'a refusal');
        $this->assertSame('TypeError', $errors[2]['exception']);
        $this->assertArrayNotHasKey('exception', $errors[3], 'not found is a refusal too');
    }
}
