<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\CreatesRecord;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * The save_callbacks of a create run once the record exists, with its ID and row
 * (pre-release review M3). Run in convertFields() with ID 0, changelanguage's
 * languageMain check loaded page 0 and refused a legitimate `page create`.
 *
 * Through the real convertFields() and createVersion(); only the database is a stand-in.
 */
class DeferredSaveCallbacksTest extends TestCase
{
    /** @var list<string> */
    public static array $log = [];

    protected function setUp(): void
    {
        self::$log = [];
        $GLOBALS['TL_DCA']['tl_probe']['fields'] = [
            'title'        => ['sql' => "varchar(255) NOT NULL default ''", 'save_callback' => [static fn ($v) => $v]],
            'languageMain' => ['sql' => "int(10) unsigned NOT NULL default 0", 'save_callback' => [static fn ($v) => $v]],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_probe']);
    }

    /**
     * @param array<string, mixed> $returns what save() hands back, by field
     */
    private function callbacks(array $returns = []): DcaCallbacks
    {
        $callbacks = $this->createMock(DcaCallbacks::class);
        $callbacks->method('create')->willReturnCallback(static function (string $t, int $id): void { self::$log[] = 'oncreate ' . $id; });
        $callbacks->method('submit')->willReturnCallback(static function (string $t, int $id): void { self::$log[] = 'onsubmit ' . $id; });
        $callbacks->method('save')->willReturnCallback(static function (string $t, int $id, array $fields, array $record) use ($returns): array {
            self::$log[] = \sprintf('save %d row=%s', $id, $record['title'] ?? '-');

            return array_replace($fields, $returns);
        });

        return $callbacks;
    }

    private function createCommand(DcaCallbacks $callbacks): DeferredProbeCreateCommand
    {
        $command = new DeferredProbeCreateCommand();
        $this->prepare($command, $callbacks);

        return $command;
    }

    private function updateCommand(DcaCallbacks $callbacks): DeferredProbeUpdateCommand
    {
        $command = new DeferredProbeUpdateCommand();
        $this->prepare($command, $callbacks);

        return $command;
    }

    private function prepare(AbstractWriteCommand $command, DcaCallbacks $callbacks): void
    {
        $command->setDcaCallbacks($callbacks);

        $versions = $this->createMock(VersionManager::class);
        $versions->method('createInitialVersion')->willReturnCallback(static function (string $t, int $id): int { self::$log[] = 'version ' . $id; return 0; });
        $command->setVersionManager($versions);

        $input = new ArrayInput(['--set' => ['title=Neu', 'languageMain=5']], $command->getDefinition());
        (new \ReflectionProperty(AbstractWriteCommand::class, 'input'))->setValue($command, $input);
        (new \ReflectionProperty(AbstractWriteCommand::class, 'output'))->setValue($command, new NullOutput());
    }

    public function testACreateRunsTheSaveCallbacksAfterTheInsertWithTheRealIdAndRow(): void
    {
        $command = $this->createCommand($this->callbacks());

        $fields = $command->convert('tl_probe', ['title' => 'Neu', 'languageMain' => '5']);
        self::$log[] = 'insert';
        $command->created('tl_probe', 7);

        $this->assertSame(['title' => 'Neu', 'languageMain' => '5'], $fields);
        $this->assertSame(['insert', 'oncreate 7', 'save 7 row=Neu', 'onsubmit 7', 'version 7'], self::$log);
        $this->assertSame([], $command->writtenBack, 'nothing changed, nothing written back');
    }

    public function testWhatACallbackChangesIsWrittenBack(): void
    {
        $command = $this->createCommand($this->callbacks(['title' => 'Neu (DE)']));

        $command->convert('tl_probe', ['title' => 'Neu', 'languageMain' => '5']);
        $command->created('tl_probe', 7);

        $this->assertSame([['tl_probe', 7, ['title' => 'Neu (DE)']]], $command->writtenBack);
    }

    public function testAnUpdateStillRunsThemInConvertFields(): void
    {
        $command = $this->updateCommand($this->callbacks());

        $command->convert('tl_probe', ['title' => 'Neu'], 7);

        $this->assertSame(['save 7 row=Alt'], self::$log, 'with the stored row, before the write');
    }
}

final class DeferredProbeCreateCommand extends AbstractWriteCommand implements CreatesRecord
{
    /** @var list<array{string, int, array<string, mixed>}> */
    public array $writtenBack = [];

    protected function configure(): void
    {
        $this->setName('test:probe:deferred-create');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function convert(string $table, array $fields, ?int $id = null): array
    {
        return $this->convertFields($table, $fields, $id);
    }

    public function created(string $table, int $id): void
    {
        $this->createVersion($table, $id, true);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return null === $id ? [] : ['id' => $id, 'title' => 'Neu', 'languageMain' => 5];
    }

    protected function writeBack(string $table, int $id, array $changed): void
    {
        $this->writtenBack[] = [$table, $id, $changed];
    }
}

final class DeferredProbeUpdateCommand extends AbstractWriteCommand
{
    protected function configure(): void
    {
        $this->setName('test:probe:deferred-update');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function convert(string $table, array $fields, ?int $id = null): array
    {
        return $this->convertFields($table, $fields, $id);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return null === $id ? [] : ['id' => $id, 'title' => 'Alt'];
    }
}
