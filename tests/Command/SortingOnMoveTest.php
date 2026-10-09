<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractModelUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A record that changes its parent goes behind the last of its new siblings.
 *
 * `--set pid=…` has moved a record since v0.27.0, but kept its `sorting`. Among the
 * new siblings it then landed wherever its old number happened to fall — first,
 * last or in between. The back end never does that: `DC_Table::cut()` always gives
 * the record a position (`getNewPosition()`). Without one, "at the end" is the same
 * rule a create follows (SortingOnCreateTest).
 *
 * Found on 2026-10-09 while checking the move operations Contao's own API gained
 * for 6.1 (contao/contao#10400). A `--set sorting=` of the caller still wins, and a
 * write that keeps the parent leaves the position alone.
 */
class SortingOnMoveTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_sorted']['config']  = ['ptable' => 'tl_parent'];
        $GLOBALS['TL_DCA']['tl_element']['config'] = ['ptable' => 'tl_article', 'dynamicPtable' => true];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_sorted'], $GLOBALS['TL_DCA']['tl_element']);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private function moved(MoveProbeCommand $subject, string $table, array $fields, array $stored): array
    {
        /** @var array<string, mixed> */
        return (new \ReflectionMethod($subject, 'sortingForMove'))->invoke($subject, $table, $fields, $stored);
    }

    private function subject(?int $max = 384): MoveProbeCommand
    {
        return new MoveProbeCommand($this->createMock(ContaoFramework::class), $max);
    }

    public function testANewParentPutsTheRecordBehindItsNewSiblings(): void
    {
        $subject = $this->subject(384);
        $fields  = $this->moved($subject, 'tl_sorted', ['pid' => 7], ['pid' => 3, 'sorting' => 64]);

        $this->assertSame(512, $fields['sorting']);
        $this->assertSame(['tl_sorted.7.'], $subject->asked);
    }

    public function testAnEmptyNewParentGetsTheFirstPosition(): void
    {
        $fields = $this->moved($this->subject(null), 'tl_sorted', ['pid' => 7], ['pid' => 3, 'sorting' => 64]);

        $this->assertSame(128, $fields['sorting']);
    }

    public function testTheSameParentKeepsThePosition(): void
    {
        $subject = $this->subject();
        $fields  = $this->moved($subject, 'tl_sorted', ['pid' => 3, 'title' => 'x'], ['pid' => 3, 'sorting' => 64]);

        $this->assertArrayNotHasKey('sorting', $fields);
        $this->assertSame([], $subject->asked);
    }

    public function testAGivenSortingWins(): void
    {
        $subject = $this->subject();
        $fields  = $this->moved($subject, 'tl_sorted', ['pid' => 7, 'sorting' => 32], ['pid' => 3, 'sorting' => 64]);

        $this->assertSame(32, $fields['sorting']);
        $this->assertSame([], $subject->asked);
    }

    public function testAWriteWithoutPidLeavesThePositionAlone(): void
    {
        $subject = $this->subject();
        $fields  = $this->moved($subject, 'tl_sorted', ['title' => 'x'], ['pid' => 3, 'sorting' => 64]);

        $this->assertArrayNotHasKey('sorting', $fields);
        $this->assertSame([], $subject->asked);
    }

    public function testATableWithoutSortingIsLeftAlone(): void
    {
        $subject = $this->subject();
        $fields  = $this->moved($subject, 'tl_sorted', ['pid' => 7], ['pid' => 3]);

        $this->assertArrayNotHasKey('sorting', $fields);
        $this->assertSame([], $subject->asked);
    }

    public function testAnotherPtableIsAMoveEvenWithTheSamePid(): void
    {
        $subject = $this->subject(128);
        $fields  = $this->moved($subject, 'tl_element', ['ptable' => 'tl_news'], ['pid' => 5, 'ptable' => 'tl_article', 'sorting' => 64]);

        $this->assertSame(256, $fields['sorting']);
        $this->assertSame(['tl_element.5.tl_news'], $subject->asked);
    }

    public function testADynamicPtableCountsOnlySiblingsOfTheSameParentTable(): void
    {
        $subject = $this->subject();
        $this->moved($subject, 'tl_element', ['pid' => 9], ['pid' => 5, 'ptable' => 'tl_article', 'sorting' => 64]);

        $this->assertSame(['tl_element.9.tl_article'], $subject->asked);
    }

    public function testAnEmptyPidMovesToTheTopLevel(): void
    {
        $subject = $this->subject(null);
        $fields  = $this->moved($subject, 'tl_sorted', ['pid' => ''], ['pid' => 3, 'sorting' => 64]);

        $this->assertSame(128, $fields['sorting']);
        $this->assertSame(['tl_sorted.0.'], $subject->asked);
    }

    public function testTheModelUpdateAppliesIt(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractModelUpdateCommand.php');

        $this->assertStringContainsString('$this->sortingForMove(', $source, AbstractModelUpdateCommand::class . ' must apply sortingForMove() before writing');
    }
}

class MoveProbeCommand extends ImageSizeUpdateCommand
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(ContaoFramework $framework, private readonly ?int $max)
    {
        parent::__construct($framework);
    }

    protected function maxSorting(string $table, int $pid, ?string $ptable): ?int
    {
        $this->asked[] = "$table.$pid.$ptable";

        return $this->max;
    }
}
