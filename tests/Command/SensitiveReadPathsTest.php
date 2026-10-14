<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractModelReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageTreeCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\SettingsReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\SettingsUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\VirtualFields;
use Webwerkwien\ContaoAiCoreBundle\Service\SensitiveFields;

/**
 * Every read path masks what Contao's detail view hides — run through the commands (v1.8.1).
 *
 * The first version of this release tested SensitiveFields and the filter refusal on
 * their own; the Fable review removed the mask from `record list`, the model reads and
 * `settings read` one by one, and the suite stayed green each time. The leak measured
 * on c5 — `record list tl_user --fields ai_api_key,password,secret` — had no test that
 * would notice it coming back.
 */
class SensitiveReadPathsTest extends TestCase
{
    private const TABLE = 'tl_probe_user';

    protected function setUp(): void
    {
        VirtualFields::$supported = true;
        $GLOBALS['TL_DCA'][self::TABLE]['fields'] = [
            'id'         => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
            'username'   => ['inputType' => 'text', 'sql' => "varchar(64) NOT NULL default ''"],
            'password'   => ['inputType' => 'password', 'sql' => "varchar(255) NOT NULL default ''"],
            'ai_api_key' => ['inputType' => 'text', 'eval' => ['hideInput' => true, 'doNotShow' => true], 'sql' => "varchar(512) NOT NULL default ''"],
            // A virtual field holding bank data in a JSON column, as Consho stores it (5.7/6.0).
            'jsonData'   => ['virtualTarget' => true, 'sql' => 'json NULL'],
            'iban'       => ['inputType' => 'text', 'targetColumn' => 'jsonData', 'eval' => ['hideInput' => true]],
            'holder'     => ['inputType' => 'text', 'targetColumn' => 'jsonData'],
        ];
    }

    protected function tearDown(): void
    {
        VirtualFields::$supported = null;
        unset($GLOBALS['TL_DCA'][self::TABLE], $GLOBALS['TL_DCA']['tl_settings'], $GLOBALS['TL_DCA']['tl_page'], $GLOBALS['TL_CONFIG']['probeApiKey']);
    }

    private function row(): array
    {
        return [
            'id'         => 3,
            'username'   => 'h.lewis',
            'password'   => '$2y$13$abcdefghijklmnopqrstuv',
            'ai_api_key' => 'sk-ant-api03-SECRET',
            'jsonData'   => '{"iban":"AT611904300234573201","holder":"Lewis"}',
        ];
    }

    private function recordList(?array &$sql = null): RecordListCommand
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(['id' => 1, 'username' => 1, 'password' => 1, 'ai_api_key' => 1, 'jsondata' => 1]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $query) use (&$sql): array {
            $sql = $query;

            return [$this->row()];
        });
        $connection->method('fetchOne')->willReturn(1);

        return new RecordListCommand($this->createMock(ContaoFramework::class), $connection);
    }

    public function testRecordListMasksTheMeasuredLeak(): void
    {
        $tester = new CommandTester($this->recordList());
        $tester->execute(['table' => self::TABLE, '--fields' => 'id,username,password,ai_api_key']);
        $row = json_decode($tester->getDisplay(), true)['results'][0];

        $this->assertSame('h.lewis', $row['username']);
        $this->assertSame('********', $row['password']);
        $this->assertSame('********', $row['ai_api_key']);
        $this->assertStringNotContainsString('SECRET', $tester->getDisplay());
    }

    /**
     * Only the sensitive key inside the JSON column: the holder next to the IBAN stays
     * readable (second Fable review — masking the whole column hid every field of a
     * Consho payment method, for which `record list` is the only read path).
     */
    public function testRecordListMasksTheSensitiveKeyInsideAVirtualFieldsColumn(): void
    {
        $tester = new CommandTester($this->recordList());
        $tester->execute(['table' => self::TABLE, '--fields' => 'id,jsonData']);

        $json = json_decode(json_decode($tester->getDisplay(), true)['results'][0]['jsonData'], true);
        $this->assertSame(['iban' => '********', 'holder' => 'Lewis'], $json);
        $this->assertStringNotContainsString('AT61', $tester->getDisplay());
    }

    public function testAColumnThatIsNoJsonObjectIsMaskedWhole(): void
    {
        $row = SensitiveFields::maskTable(self::TABLE, ['jsonData' => 'not json AT611904300234573201']);

        $this->assertSame('********', $row['jsonData']);
    }

    public function testRecordListRefusesAGuessAtAKeyBeforeAnyQuery(): void
    {
        $sql    = null;
        $tester = new CommandTester($this->recordList($sql));
        $tester->execute(['table' => self::TABLE, '--filter' => ['ai_api_key=sk-ant-api03-SECRET']]);

        $this->assertSame('error', json_decode($tester->getDisplay(), true)['status']);
        $this->assertNull($sql, 'refused before anything was read');
    }

    public function testAModelReadMasksItsRecord(): void
    {
        VirtualFields::$supported = false;

        $tester = new CommandTester(new ProbeUserReadCommand($this->createMock(ContaoFramework::class)));
        $tester->execute(['id' => '3']);
        $row = json_decode($tester->getDisplay(), true);

        $this->assertSame('h.lewis', $row['username']);
        $this->assertSame('********', $row['password']);
        $this->assertSame('********', $row['ai_api_key']);
    }

    public function testSettingsReadMasksAKeyAnExtensionKeepsThere(): void
    {
        $GLOBALS['TL_DCA']['tl_settings']['fields'] = [
            'probeApiKey' => ['inputType' => 'text', 'eval' => ['hideInput' => true]],
            'adminEmail'  => ['inputType' => 'text'],
        ];
        $GLOBALS['TL_CONFIG']['probeApiKey'] = 'key-123456789';

        $tester = new CommandTester(new SettingsReadCommand($this->createMock(ContaoFramework::class)));
        $tester->execute([]);

        $this->assertSame('********', json_decode($tester->getDisplay(), true)['settings']['probeApiKey']['value']);
        $this->assertStringNotContainsString('key-1234', $tester->getDisplay());
    }

    /**
     * change() is the only producer of a change entry in the update loop; a real write
     * needs localconfig.php and a container, so the entry is made here directly.
     */
    public function testSettingsUpdateAnswersAKeyChangeMasked(): void
    {
        $GLOBALS['TL_DCA']['tl_settings']['fields'] = [
            'probeApiKey' => ['inputType' => 'password'],
            'adminEmail'  => ['inputType' => 'text'],
        ];

        $command = new SettingsUpdateCommand($this->createMock(ContaoFramework::class));
        $change  = new \ReflectionMethod($command, 'change');

        $this->assertSame(['from' => '********', 'to' => '********'], $change->invoke($command, 'probeApiKey', 'old-key', 'new-key'));
        $this->assertSame(['from' => '', 'to' => '********'], $change->invoke($command, 'probeApiKey', '', 'new-key'), 'set for the first time');
        $this->assertSame(['from' => 'a@example.org', 'to' => 'b@example.org'], $change->invoke($command, 'adminEmail', 'a@example.org', 'b@example.org'));
    }

    public function testTheUpdateLoopBuildsItsEntriesThroughChange(): void
    {
        // Rule 24 by the cheapest means: the loop has no second way to fill $changed.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/SettingsUpdateCommand.php');

        $this->assertSame(1, preg_match_all('/\$changed\[\$field\]\s*=/', $source));
        $this->assertStringContainsString('$changed[$field] = $this->change(', $source);
    }

    public function testPageTreeRefusesASensitiveFieldAnExtensionAddsToPages(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['fields'] = [
            'title'       => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
            'probeSecret' => ['inputType' => 'password', 'sql' => "varchar(255) NOT NULL default ''"],
        ];

        // Through the command: refused as a refusal (JSON, no `exception`), before any query.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('createSchemaManager');

        $tester = new CommandTester(new PageTreeCommand($this->createMock(ContaoFramework::class), $connection));
        $tester->execute(['--fields' => 'title,probeSecret']);
        $answer = json_decode($tester->getDisplay(), true);

        $this->assertSame('error', $answer['status']);
        $this->assertStringContainsString('probeSecret is not shown', $answer['message']);
        $this->assertArrayNotHasKey('exception', $answer, 'a refusal, not a defect');
    }

    /**
     * A model read expands virtual fields first and masks them by name: the IBAN goes,
     * the holder next to it stays (second Fable review — untested until then).
     */
    public function testAModelReadMasksASensitiveVirtualFieldByName(): void
    {
        $tester = new CommandTester(new ProbeUserReadCommand($this->createMock(ContaoFramework::class)));
        $tester->execute(['id' => '3']);
        $row = json_decode($tester->getDisplay(), true);

        $this->assertSame('********', $row['iban']);
        $this->assertSame('Lewis', $row['holder']);
        $this->assertArrayNotHasKey('jsonData', $row);
    }
}

/** A stand-in for a Contao model: what AbstractModelReadCommand calls on one. */
final class ProbeUserModel
{
    public static function getTable(): string
    {
        return 'tl_probe_user';
    }

    public static function findById(int $id): object
    {
        return new class {
            /** @return array<string, mixed> */
            public function row(): array
            {
                return [
                    'id' => 3, 'username' => 'h.lewis', 'password' => '$2y$13$hash', 'ai_api_key' => 'sk-ant-api03-SECRET',
                    'jsonData' => '{"iban":"AT611904300234573201","holder":"Lewis"}',
                ];
            }
        };
    }
}

final class ProbeUserReadCommand extends AbstractModelReadCommand
{
    protected static $defaultName = 'probe:user:read';

    protected function configure(): void
    {
        $this->setName('probe:user:read');
        parent::configure();
    }

    protected function modelClass(): string
    {
        return ProbeUserModel::class;
    }

    protected function entityName(): string
    {
        return 'User';
    }
}
