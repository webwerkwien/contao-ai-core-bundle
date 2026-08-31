<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * A table with no curated column list describes itself the way Contao does.
 *
 * The default arm used to return `['id', 'tstamp']` for everything outside the
 * ten curated tables, which made the zero-argument call useless in exactly the
 * case this command exists for: `record:list tl_image_size` answered "I do not
 * know this table, show me what is in it" with two columns that say nothing.
 *
 * `list.label.fields` is the column set of Contao's own back end list view —
 * the DCA's answer to which fields identify a record. Measured across a live
 * 5.7 install: 22 of the 29 non-curated tables declare it. The 7 that do not
 * are system tables with no list view at all, where id and tstamp really is
 * the whole story.
 *
 * The curated ten are deliberately untouched: their hand-picked lists are
 * richer than their label fields (tl_page labels with `title` alone, while the
 * curated list carries pid, alias, type and published as well).
 */
class RecordListDefaultColumnsTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function command(?Connection $connection = null): RecordListCommand
    {
        return new RecordListCommand(
            $this->createMock(ContaoFramework::class),
            $connection ?? $this->createMock(Connection::class),
        );
    }

    /**
     * Reached by reflection on purpose. Column selection is a pure function of
     * the DCA globals, so this pins it without booting a container — which the
     * command's own doExecute() would need, since it calls loadDataContainer()
     * before anything else. The end-to-end behaviour is covered below, guarded.
     *
     * @return list<string>
     */
    private function columnsFor(string $table): array
    {
        $method = new \ReflectionMethod(RecordListCommand::class, 'labelColumns');

        return $method->invoke($this->command(), $table);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_image_size'], $GLOBALS['TL_DCA']['tl_search']);
        parent::tearDown();
    }

    public function testALabelFieldIsPutBetweenIdAndTstamp(): void
    {
        $GLOBALS['TL_DCA']['tl_image_size']['list']['label']['fields'] = ['name'];

        $this->assertSame(['id', 'name', 'tstamp'], $this->columnsFor('tl_image_size'));
    }

    public function testSeveralLabelFieldsKeepTheOrderContaoDeclaresThemIn(): void
    {
        // tl_module really does declare ['name', 'type'].
        $GLOBALS['TL_DCA']['tl_image_size']['list']['label']['fields'] = ['name', 'type'];

        $this->assertSame(['id', 'name', 'type', 'tstamp'], $this->columnsFor('tl_image_size'));
    }

    public function testATableWithoutLabelFieldsKeepsTheOldFallback(): void
    {
        // tl_search and friends: no back end list, nothing to label with.
        $GLOBALS['TL_DCA']['tl_search']['fields'] = ['url' => []];

        $this->assertSame(['id', 'tstamp'], $this->columnsFor('tl_search'));
    }

    public function testAnUnloadedTableFallsBackRatherThanFailing(): void
    {
        $this->assertSame(['id', 'tstamp'], $this->columnsFor('tl_never_loaded'));
    }

    public function testIdAsALabelFieldIsNotDuplicated(): void
    {
        $GLOBALS['TL_DCA']['tl_image_size']['list']['label']['fields'] = ['id', 'name'];

        $this->assertSame(['id', 'name', 'tstamp'], $this->columnsFor('tl_image_size'));
    }

    /**
     * `sorting.fields` was considered as a middle tier and dropped: it fired on
     * none of the 29 measured tables, and it answers what to sort by rather
     * than what a record is. This pins that it stays dropped.
     */
    public function testSortingFieldsAreNotUsedAsAFallback(): void
    {
        $GLOBALS['TL_DCA']['tl_image_size']['list']['sorting']['fields'] = ['name'];

        $this->assertSame(['id', 'tstamp'], $this->columnsFor('tl_image_size'));
    }

    /**
     * The whole point, end to end: no --fields, and the answer is still useful.
     */
    public function testTheCommandListsTheLabelColumnByDefault(): void
    {
        $this->skipWithoutContaoContainer();

        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`'
        );
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchOne')->willReturn(0);

        $tester = new CommandTester($this->command($connection));
        $tester->execute(['table' => 'tl_image_size']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertContains('name', $out['columns'], 'A size is identified by its name, not its id.');
    }
}
