<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractModelReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\VersionReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\VirtualFields;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * Virtual fields (5.7/6.0) at the command layer (v1.6.0): writeBack() folds them into
 * their target, the column check lets them pass, `read` and `version read` show them
 * expanded. Through the real methods; only the database is a stand-in. Written after
 * the Fable review, whose mutations of all four places left the suite green.
 */
class VirtualFieldsInCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        VirtualFields::$supported = true;
        $GLOBALS['TL_DCA']['tl_probe_vf']['fields'] = [
            'id'       => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
            'title'    => ['sql' => "varchar(255) NOT NULL default ''"],
            'jsonData' => ['virtualTarget' => true, 'sql' => ['type' => 'json', 'notnull' => false]],
            'bic'      => ['inputType' => 'text', 'targetColumn' => 'jsonData'],
            'holder'   => ['inputType' => 'text', 'targetColumn' => 'jsonData'],
        ];
    }

    protected function tearDown(): void
    {
        VirtualFields::$supported = null;
        unset($GLOBALS['TL_DCA']['tl_probe_vf']);
    }

    public function testWriteBackFoldsAVirtualFieldKeepingItsNeighbours(): void
    {
        $command = new VirtualFieldsProbeWriteCommand();
        $command->writeBackFor('tl_probe_vf', 3, ['title' => 'Neu', 'holder' => 'Neu GmbH']);

        $this->assertSame([['tl_probe_vf', 3, ['title' => 'Neu', 'jsonData' => '{"bic":"BICX","holder":"Neu GmbH"}']]], $command->updates);
    }

    public function testWriteBackWithoutVirtualFieldsWritesAsGiven(): void
    {
        $command = new VirtualFieldsProbeWriteCommand();
        $command->writeBackFor('tl_probe_vf', 3, ['title' => 'Neu']);

        $this->assertSame([['tl_probe_vf', 3, ['title' => 'Neu']]], $command->updates);
    }

    public function testTheColumnCheckLetsVirtualFieldsPassAndStillRefusesATypo(): void
    {
        $command = new VirtualFieldsProbeWriteCommand();
        $command->refuse('tl_probe_vf', ['title' => 'a', 'bic' => 'B']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a column of tl_probe_vf: bci');

        $command->refuse('tl_probe_vf', ['bci' => 'B']);
    }

    public function testReadShowsVirtualFieldsInsteadOfTheirColumn(): void
    {
        $tester = new CommandTester(new VirtualFieldsProbeReadCommand($this->createMock(ContaoFramework::class)));
        $tester->execute(['id' => '3']);

        $answer = json_decode($tester->getDisplay(), true);
        $this->assertSame('BICX', $answer['bic']);
        $this->assertSame('Alt', $answer['holder']);
        $this->assertArrayNotHasKey('jsonData', $answer);
    }

    public function testVersionReadShowsVirtualFieldsInsteadOfTheirColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['tstamp' => 1, 'version' => 2, 'username' => 'claude', 'active' => 1]);
        $versions = $this->createMock(VersionManager::class);
        $versions->method('loadVersionData')->willReturn(['id' => 3, 'title' => 'T', 'jsonData' => '{"bic":"BICX","holder":"Alt"}']);

        $tester = new CommandTester(new VersionReadCommand($this->createMock(ContaoFramework::class), $connection, $versions));
        $tester->execute(['--table' => 'tl_probe_vf', '--id' => '3', '--ver' => '2']);

        $data = json_decode($tester->getDisplay(), true)['data'];
        $this->assertSame(['id' => 3, 'title' => 'T', 'bic' => 'BICX', 'holder' => 'Alt'], $data);
    }

    /** On 5.3 there is nothing to expand, and no DCA is loaded for it (as in v1.5.0). */
    public function testVersionReadOnContao53LoadsNoDcaAndShowsTheRow(): void
    {
        VirtualFields::$supported = false;

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['tstamp' => 1, 'version' => 2, 'username' => 'claude', 'active' => 1]);
        $versions = $this->createMock(VersionManager::class);
        $versions->method('loadVersionData')->willReturn(['id' => 3, 'jsonData' => '{"bic":"BICX"}']);

        // No TL_DCA for this table: loading it would need a booted framework.
        $tester = new CommandTester(new VersionReadCommand($this->createMock(ContaoFramework::class), $connection, $versions));
        $tester->execute(['--table' => 'tl_probe_vf_53', '--id' => '3', '--ver' => '2']);

        $this->assertSame(['id' => 3, 'jsonData' => '{"bic":"BICX"}'], json_decode($tester->getDisplay(), true)['data']);
    }
}

final class VirtualFieldsProbeWriteCommand extends AbstractWriteCommand
{
    /** @var list<array{string, int, array<string, mixed>}> */
    public array $updates = [];

    protected function configure(): void
    {
        $this->setName('test:probe:virtual-write');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $changed */
    public function writeBackFor(string $table, int $id, array $changed): void
    {
        $this->writeBack($table, $id, $changed);
    }

    /** @param array<string, mixed> $fields */
    public function refuse(string $table, array $fields): void
    {
        $this->refuseUnknownFields($table, $fields);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return ['id' => $id, 'title' => 'Alt', 'jsonData' => '{"bic":"BICX","holder":"Alt"}'];
    }

    protected function updateRow(string $table, int $id, array $set): void
    {
        $this->updates[] = [$table, $id, $set];
    }

    protected function tableColumns(string $table): array
    {
        return ['id', 'title', 'jsonData'];
    }
}

final class VirtualFieldsProbeReadCommand extends AbstractModelReadCommand
{
    protected function configure(): void
    {
        $this->setName('test:probe:virtual-read');
        parent::configure();
    }

    protected function modelClass(): string
    {
        return VirtualFieldsProbeModel::class;
    }

    protected function entityName(): string
    {
        return 'Probe';
    }
}

/** Stands in for a model on 5.3 — which, unlike 5.7's, does not expand on load. */
final class VirtualFieldsProbeModel
{
    public static function findById(int $id): object
    {
        return new class($id) {
            public function __construct(private readonly int $id)
            {
            }

            /** @return array<string, mixed> */
            public function row(): array
            {
                return ['id' => $this->id, 'title' => 'T', 'jsonData' => '{"bic":"BICX","holder":"Alt"}'];
            }
        };
    }

    public static function getTable(): string
    {
        return 'tl_probe_vf';
    }
}
