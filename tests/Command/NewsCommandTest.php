<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsRepairHeadlinesCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

class NewsCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function vm(): VersionManager
    {
        return $this->createMock(VersionManager::class);
    }

    // --- NewsCreateCommand ---
    // Note: NewsCreateCommand uses --headline (not --title)

    public function testCreateRequiresHeadline(): void
    {
        $cmd = new NewsCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--pid' => '1']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('headline', $out['message']);
    }

    public function testCreateRequiresPid(): void
    {
        $cmd = new NewsCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--headline' => 'Breaking News']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- NewsReadCommand ---

    public function testReadReturnsErrorForMissingRecord(): void
    {
        $this->skipWithoutContaoContainer();
        $cmd = new NewsReadCommand($this->fw());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- NewsDeleteCommand ---

    public function testDeleteReturnsErrorForMissingRecord(): void
    {
        $this->skipWithoutContaoContainer();
        $cmd = new NewsDeleteCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- NewsUpdateCommand ---

    public function testUpdateCommandName(): void
    {
        $cmd = new NewsUpdateCommand($this->fw());
        $this->assertSame('contao:news:update', $cmd->getName());
    }

    // --- tl_news.headline is plain text, not an inputUnit field ---

    /**
     * Regression guard: tl_news.headline is a plain varchar title. The removed
     * --unit option used to serialize it into {value, unit}, which Contao then
     * rendered verbatim as `a:2:{…}` in listings, feeds and the front end.
     */
    public function testCreateHasNoUnitOption(): void
    {
        $cmd = new NewsCreateCommand($this->fw());
        $this->assertFalse(
            $cmd->getDefinition()->hasOption('unit'),
            'tl_news.headline is plain text — a headline level option must not exist'
        );
    }

    // --- NewsRepairHeadlinesCommand ---

    private function repairCmd(Connection $connection): NewsRepairHeadlinesCommand
    {
        $cmd = new NewsRepairHeadlinesCommand($connection);
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        return $cmd;
    }

    public function testRepairUnpacksSerializedHeadlines(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'headline' => serialize(['value' => 'Contao ist beliebt', 'unit' => 'h1'])],
            ['id' => 2, 'headline' => 'Schon sauberer Titel'],
        ]);
        $connection->expects($this->once())
            ->method('update')
            ->with('tl_news', ['headline' => 'Contao ist beliebt'], ['id' => 1]);

        $tester = new CommandTester($this->repairCmd($connection));
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(2, $out['scanned']);
        $this->assertSame(1, $out['repaired']);
        $this->assertSame('Contao ist beliebt', $out['records'][0]['to']);
    }

    public function testRepairDryRunDoesNotWrite(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'headline' => serialize(['value' => 'Testtitel', 'unit' => 'h1'])],
        ]);
        $connection->expects($this->never())->method('update');

        $tester = new CommandTester($this->repairCmd($connection));
        $tester->execute(['--dry-run' => true]);
        $out = json_decode($tester->getDisplay(), true);

        $this->assertTrue($out['dry_run']);
        $this->assertSame(1, $out['repaired']);
    }

    /**
     * A genuine title must never be mangled — neither one that merely starts
     * with "a:" nor a serialized payload without a `value` key.
     */
    public function testRepairLeavesGenuineTitlesUntouched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'headline' => 'a: ein ganz normaler Titel'],
            ['id' => 2, 'headline' => serialize(['unit' => 'h1'])],
            ['id' => 3, 'headline' => ''],
        ]);
        $connection->expects($this->never())->method('update');

        $tester = new CommandTester($this->repairCmd($connection));
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);

        $this->assertSame(0, $out['repaired']);
    }

    public function testRepairIsIdempotent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'headline' => 'Contao ist beliebt'],
        ]);
        $connection->expects($this->never())->method('update');

        $tester = new CommandTester($this->repairCmd($connection));
        $tester->execute([]);
        $out = json_decode($tester->getDisplay(), true);

        $this->assertSame(0, $out['repaired']);
    }
}
