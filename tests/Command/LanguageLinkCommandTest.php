<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\LanguageLinkCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\ChangeLanguageLinks;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\RecordWriterInterface;

/**
 * `contao:language:link` through its real doExecute() and convertFields() (before,
 * no test called the command). The database is a stand-in: the stored row and the writer.
 *
 * `--main 0` removes a link. It reaches the writer only because the option check in
 * convertFields() leaves `options_callback` fields alone — languageMain and master are
 * such fields. Should that check ever cover callbacks, unlinking would break silently;
 * this test is what notices.
 */
class LanguageLinkCommandTest extends TestCase
{
    /** @var list<array{string, int, array<string, mixed>}> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->written = [];

        foreach (['tl_page', 'tl_news', 'tl_news_archive'] as $table) {
            $GLOBALS['TL_DCA'][$table]['fields'] = [
                'languageMain' => ['inputType' => 'select', 'options_callback' => [self::class, 'neverCalled'], 'eval' => ['includeBlankOption' => true], 'sql' => "int(10) unsigned NOT NULL default 0"],
                'master'       => ['inputType' => 'select', 'options_callback' => [self::class, 'neverCalled'], 'eval' => ['includeBlankOption' => true], 'sql' => "int(10) unsigned NOT NULL default 0"],
            ];
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_page'], $GLOBALS['TL_DCA']['tl_news'], $GLOBALS['TL_DCA']['tl_news_archive']);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function link(array $options, array $record, ?string $refusal = null): array
    {
        $links = $this->createMock(ChangeLanguageLinks::class);
        // The real rule for the field; only the refusal is a stand-in.
        $real = new ChangeLanguageLinks($this->createStub(\Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver::class));
        $links->method('field')->willReturnCallback(static fn (string $table, array $row): string => $real->field($table, $row));
        $links->method('refusal')->willReturn($refusal);

        $command = new class ($this->createMock(ContaoFramework::class), $links, $record) extends LanguageLinkCommand {
            /** @param array<string, mixed> $record */
            public function __construct(ContaoFramework $framework, ChangeLanguageLinks $links, private readonly array $record)
            {
                parent::__construct($framework, $links);
            }

            protected function storedRow(string $table, ?int $id): array
            {
                return $this->record;
            }

            protected function tableColumns(string $table): array
            {
                return ['id', 'tstamp', 'type', 'languageMain', 'master'];
            }
        };

        $writer = $this->createMock(RecordWriterInterface::class);
        $writer->method('update')->willReturnCallback(function (string $table, int $id, array $fields): array {
            $this->written[] = [$table, $id, $fields];

            return array_keys($fields);
        });

        $command->setRecordWriter($writer);

        if (null !== $this->callbacks) {
            $command->setDcaCallbacks($this->callbacks);
        }
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));

        $tester = new CommandTester($command);
        $tester->execute($options);

        return json_decode(trim($tester->getDisplay()), true);
    }

    public function testAPageIsLinkedThroughTheWriter(): void
    {
        $answer = $this->link(['--table' => 'tl_page', '--id' => '201', '--main' => '1'], ['id' => 201, 'type' => 'regular', 'languageMain' => 0]);

        $this->assertSame('ok', $answer['status']);
        $this->assertSame('languageMain', $answer['field']);
        $this->assertSame([['tl_page', 201, ['languageMain' => 1]]], $this->written);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unlinkable(): iterable
    {
        yield 'page'    => ['tl_page', 'languageMain'];
        yield 'news'    => ['tl_news', 'languageMain'];
        yield 'archive' => ['tl_news_archive', 'master'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unlinkable')]
    public function testMainZeroRemovesTheLink(string $table, string $field): void
    {
        $answer = $this->link(['--table' => $table, '--id' => '7', '--main' => '0'], ['id' => 7, 'type' => 'regular', $field => 4]);

        $this->assertSame('ok', $answer['status'], $answer['message'] ?? '');
        $this->assertSame(4, $answer['previous']);
        $this->assertSame([[$table, 7, [$field => 0]]], $this->written);
    }

    /**
     * changelanguage adds languageMain to the palette only for the edit mask; its
     * duplicate check runs only because the command marks the field as set on purpose
     * (without this test, removing that line left every test green).
     */
    public function testTheLinkFieldReachesTheSaveCallbacksAsSetOnPurpose(): void
    {
        $requested = null;
        $callbacks = $this->createMock(\Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks::class);
        $callbacks->method('save')->willReturnCallback(static function (string $t, int $id, array $fields, array $row, array $skip, array $req) use (&$requested): array {
            $requested = $req;

            return $fields;
        });

        $this->callbacks = $callbacks;
        $this->link(['--table' => 'tl_page', '--id' => '201', '--main' => '1'], ['id' => 201, 'type' => 'regular', 'languageMain' => 0]);

        $this->assertContains('languageMain', (array) $requested);
    }

    private ?\Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks $callbacks = null;

    public function testARefusedTargetWritesNothing(): void
    {
        $answer = $this->link(['--table' => 'tl_page', '--id' => '201', '--main' => '99'], ['id' => 201, 'type' => 'regular', 'languageMain' => 0], 'Page 99 is not in the tree of the fallback root.');

        $this->assertSame('error', $answer['status']);
        $this->assertStringContainsString('not in the tree of the fallback root', $answer['message']);
        $this->assertSame([], $this->written);
    }

    public function testTheSameLinkAgainIsUnchanged(): void
    {
        $answer = $this->link(['--table' => 'tl_page', '--id' => '201', '--main' => '1'], ['id' => 201, 'type' => 'regular', 'languageMain' => 1]);

        $this->assertTrue($answer['unchanged']);
        $this->assertSame([], $this->written);
    }

    public function testAnUnknownTableAndAMissingRecordAreRefused(): void
    {
        $this->assertStringContainsString('--table must be one of', $this->link(['--table' => 'tl_content', '--id' => '1', '--main' => '1'], [])['message']);
        $this->assertStringContainsString('Record not found', $this->link(['--table' => 'tl_page', '--id' => '1', '--main' => '1'], [])['message']);
        $this->assertSame([], $this->written);
    }

    public static function neverCalled(): array
    {
        throw new \LogicException('the option check must not resolve the callback');
    }
}
