<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Dca;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\StringType;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\PageTreeCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UndoReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UndoRestoreCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\TableColumns;
use Webwerkwien\ContaoAiCoreBundle\Service\RecordCascadeCollector;

/**
 * A column named like an SQL keyword is a column (v1.16.1).
 *
 * Doctrine keys listTableColumns() by `strtolower(getQuotedName())`, and that
 * quotes every name on the platform's keyword list: `rows` (tl_layout) and
 * `order` come back under "`rows`" and "`order`", on MySQL 8 `groups`
 * (tl_page, tl_member, tl_user) as well. Every caller that compared against the
 * keys treated those columns as missing: record:list refused them,
 * undo:restore put a row back without them.
 *
 * The schema mock builds its keys exactly as Doctrine does, so a return to the
 * keys fails here.
 */
class TableColumnsTest extends TestCase
{
    private const COLUMNS = ['id', 'name', 'rows', 'groups', 'singleSRC'];

    private function connection(): Connection
    {
        $platform = new MySQL80Platform();
        $list     = [];

        foreach (self::COLUMNS as $name) {
            $column = new Column($name, new StringType());
            $list[strtolower($column->getQuotedName($platform))] = $column;
        }

        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn($list);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);

        return $connection;
    }

    /**
     * Guards the premise: if Doctrine stopped quoting, this test would say so
     * rather than the others passing for the wrong reason.
     */
    public function testDoctrineKeysAKeywordColumnQuoted(): void
    {
        $keys = array_keys($this->connection()->createSchemaManager()->listTableColumns('tl_layout'));

        $this->assertContains('`rows`', $keys);
        $this->assertContains('`groups`', $keys);
        $this->assertContains('singlesrc', $keys);
    }

    public function testTheNamesAreTheRealNames(): void
    {
        $this->assertSame(self::COLUMNS, TableColumns::names($this->connection(), 'tl_layout'));
        $this->assertSame(['id', 'name', 'rows', 'groups', 'singlesrc'], TableColumns::lowerNames($this->connection(), 'tl_layout'));
    }

    public function testRecordListAcceptsAKeywordColumn(): void
    {
        $command = new RecordListCommand($this->createMock(ContaoFramework::class), $this->connection());
        $columns = (new \ReflectionMethod($command, 'existingColumns'))->invoke($command, 'tl_layout', ['id', 'rows', 'groups', 'singleSRC', 'missing']);

        $this->assertSame(['id', 'rows', 'groups', 'singleSRC'], $columns);
    }

    /**
     * The data-loss case: a column the restore believes gone is left out of the
     * insert, and the row comes back with the column's default.
     */
    public function testUndoRestoreKeepsAKeywordColumn(): void
    {
        $command = new UndoRestoreCommand($this->createMock(ContaoFramework::class), $this->connection());

        $this->assertContains('rows', (new \ReflectionMethod($command, 'columnsOf'))->invoke($command, 'tl_layout'));
    }

    public function testUndoReadDoesNotReportAKeywordColumnAsDropped(): void
    {
        $command = new UndoReadCommand($this->createMock(ContaoFramework::class), $this->connection());

        $this->assertContains('rows', (new \ReflectionMethod($command, 'columnsOf'))->invoke($command, 'tl_layout'));
    }

    public function testPageTreeKeepsAKeywordField(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['fields'] = ['groups' => ['sql' => 'blob NULL']];

        try {
            $command = new PageTreeCommand($this->createMock(ContaoFramework::class), $this->connection());
            $fields  = (new \ReflectionMethod($command, 'resolveFields'))->invoke($command, 'groups');
        } finally {
            unset($GLOBALS['TL_DCA']['tl_page']);
        }

        $this->assertContains('groups', $fields);
    }

    public function testTheCascadeSeesAKeywordColumn(): void
    {
        $collector = new RecordCascadeCollector($this->connection());
        $hasColumn = new \ReflectionMethod($collector, 'hasColumn');

        $this->assertTrue($hasColumn->invoke($collector, 'tl_layout', 'rows'));
        $this->assertTrue($hasColumn->invoke($collector, 'tl_layout', 'singleSRC'));
        $this->assertFalse($hasColumn->invoke($collector, 'tl_layout', 'missing'));
    }
}
