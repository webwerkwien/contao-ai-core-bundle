<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Dca;

use Contao\CoreBundle\EventListener\DataContainer\AccordionListener;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\RecordDataContainer;

/**
 * The record Contao's callbacks see on the console has to look like the one they see in
 * the back end.
 *
 * 🔴 **Found on 2026-09-18 in the log of web.werk.wien:** six warnings
 * `Undefined array key "ptable"` from Contao's own AccordionListener (an onpalette
 * callback of tl_content), one per `schema mandatory tl_content --set type=…` call.
 * Reproduced on c5 (Contao 5.7.13) with a single call.
 *
 * Two differences from Contao's DataContainer::getCurrentRecord():
 *
 * 1. The record held only the `--set` values. In the back end a record is a full row —
 *    even a new element exists as a row with every column's default before its palette
 *    is built — so a callback may read any column.
 * 2. It answered *every* request with that record, whatever ID and table were asked
 *    for. The AccordionListener asks for the parent element by ID; it got the record
 *    itself back as its own parent.
 */
class RecordDataContainerTest extends TestCase
{
    private mixed $previousContainer = null;

    protected function setUp(): void
    {
        // A container without a database, whether or not CONTAO_ROOT booted a real one:
        // the tests without a connection must not reach an installation's tl_content.
        $this->previousContainer = System::getContainer();
        System::setContainer(new ContainerBuilder());
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(System::class, 'objContainer'))->setValue(null, $this->previousContainer);
    }

    public function testTheRecordHasEveryColumnWithItsDefaultAsARealRowDoes(): void
    {
        $dc = RecordDataContainer::create('tl_content', 0, ['type' => 'text'], $this->db());

        $record = $dc->getCurrentRecord();

        $this->assertSame('tl_article', $record['ptable']);
        $this->assertSame(0, $record['pid'], 'an integer column reads back as int, as SELECT * returns it');
        $this->assertSame(0, $record['sorting'], 'NOT NULL without a default: 0 for an integer');
        $this->assertSame('', $record['cssID'], 'NOT NULL without a default: empty for a string');
        $this->assertNull($record['text']);
        $this->assertSame($record, $dc->getActiveRecord());
    }

    public function testTheColumnsAreReadOncePerConnectionAndTable(): void
    {
        $db = $this->db([], $this->once());

        RecordDataContainer::create('tl_content', 0, ['type' => 'text'], $db);
        RecordDataContainer::create('tl_content', 0, ['type' => 'list'], $db);
    }

    public function testAnotherTableIsAskedForByItsOwnName(): void
    {
        $dc = RecordDataContainer::create('tl_content', 0, ['type' => 'text'], $this->db([5 => ['id' => '5', 'type' => 'accordion']]));

        $this->assertNull($dc->getCurrentRecord(5, 'tl_article'), 'the tl_content row 5 is not tl_article 5');
    }

    public function testNoRowHasANonPositiveId(): void
    {
        $dc = RecordDataContainer::create('tl_content', 3, ['type' => 'text'], $this->db([-1 => ['id' => '-1']]));

        $this->assertNull($dc->getCurrentRecord(-1));
        $this->assertNull($dc->getCurrentRecord('abc', 'tl_article'));
    }

    public function testGivenValuesWinOverTheDefaults(): void
    {
        $dc = RecordDataContainer::create('tl_content', 0, ['type' => 'list', 'ptable' => 'tl_news'], $this->db());

        $this->assertSame('list', $dc->getCurrentRecord()['type']);
        $this->assertSame('tl_news', $dc->getCurrentRecord()['ptable']);
    }

    public function testTheOwnRecordAnswersNoIdZeroAndItsOwnId(): void
    {
        $dc = RecordDataContainer::create('tl_content', 7, ['type' => 'text'], $this->db());

        foreach ([null, 0, 7, '7'] as $id) {
            $this->assertSame('text', $dc->getCurrentRecord($id)['type'] ?? null, var_export($id, true));
        }
        $this->assertSame('text', $dc->getCurrentRecord(7, 'tl_content')['type'] ?? null);
    }

    public function testAnotherRowIsReadFromTheDatabaseAsContaoDoes(): void
    {
        $dc = RecordDataContainer::create('tl_content', 0, ['type' => 'text'], $this->db([5 => ['id' => '5', 'type' => 'accordion']]));

        $this->assertSame('accordion', $dc->getCurrentRecord(5, 'tl_content')['type'] ?? null);
        $this->assertNull($dc->getCurrentRecord(6, 'tl_content'));
    }

    public function testAnotherRowIsNeverAnsweredWithThisRecord(): void
    {
        // Without a database there is nothing to read — but the own record is not the answer.
        $dc = RecordDataContainer::create('tl_content', 0, ['type' => 'text', 'ptable' => 'tl_content', 'pid' => '5']);

        $this->assertNull($dc->getCurrentRecord(5, 'tl_content'));
        $this->assertNull($dc->getCurrentRecord(0, 'tl_article'));
    }

    public function testWithoutADatabaseTheRecordStaysAsGiven(): void
    {
        $dc = RecordDataContainer::create('tl_content', 0, ['type' => 'text']);

        $this->assertSame(['type' => 'text'], $dc->getCurrentRecord());
    }

    public function testAnEmptyRecordStaysNoRecord(): void
    {
        $this->assertNull(RecordDataContainer::create('tl_content', 0, [], $this->db())->getCurrentRecord());
    }

    public function testContaosAccordionListenerRunsWithoutAWarning(): void
    {
        $dc = RecordDataContainer::create('tl_content', 0, ['type' => 'text'], $this->db());

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $palette = (new AccordionListener())('{type_legend},type,headline', $dc);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('{type_legend},type,headline', $palette);
    }

    public function testInsideAnAccordionTheSectionFieldsAppearAsInTheBackEnd(): void
    {
        $dc = RecordDataContainer::create(
            'tl_content',
            0,
            ['type' => 'text', 'ptable' => 'tl_content', 'pid' => '5'],
            $this->db([5 => ['id' => '5', 'type' => 'accordion']]),
        );

        $this->assertStringContainsString('sectionHeadline', (new AccordionListener())('{type_legend},type,headline', $dc));
    }

    public function testNotInsideAnAccordionTheSectionFieldsStayAway(): void
    {
        // Known non-match: before the fix the record was its own parent, so a record of
        // type `accordion` inside tl_content would have added the fields to itself.
        $dc = RecordDataContainer::create(
            'tl_content',
            0,
            ['type' => 'accordion', 'ptable' => 'tl_content', 'pid' => '5'],
            $this->db([5 => ['id' => '5', 'type' => 'text']]),
        );

        $this->assertStringNotContainsString('sectionHeadline', (new AccordionListener())('{type_legend},type,headline', $dc));
    }

    /**
     * A connection whose tl_content has six columns (defaults as MySQL reports them)
     * and the given rows.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function db(array $rows = [], ?InvocationOrder $columnReads = null): Connection
    {
        $columns = [
            new Column('pid', Type::getType(Types::INTEGER), ['default' => '0', 'notnull' => true]),
            new Column('ptable', Type::getType(Types::STRING), ['default' => 'tl_article', 'notnull' => true]),
            new Column('type', Type::getType(Types::STRING), ['default' => 'text', 'notnull' => true]),
            new Column('sorting', Type::getType(Types::INTEGER), ['default' => null, 'notnull' => true]),
            new Column('cssID', Type::getType(Types::STRING), ['default' => null, 'notnull' => true]),
            new Column('text', Type::getType(Types::TEXT), ['default' => null, 'notnull' => false]),
        ];

        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->expects($columnReads ?? $this->any())->method('listTableColumns')->willReturn($columns);

        $db = $this->createMock(Connection::class);
        $db->method('createSchemaManager')->willReturn($schema);
        $db->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        // Rows exist in tl_content only; a query naming another table finds nothing.
        $db->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array|false => str_contains($sql, '`tl_content`')
                ? ($rows[(int) $params[0]] ?? false)
                : false,
        );

        return $db;
    }
}
