<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A child needs a parent that exists.
 *
 * Found on 2026-09-18 on c5: `content create --pid 99999` stored an element
 * below an article that does not exist, and `article`, `news` and `faq create`
 * did the same. The back end cannot get there, because `DC_Table` creates a
 * child from its parent's list. Such a record is reachable from nowhere: no
 * parent lists it, no delete cascade removes it.
 */
class MissingParentRefusalTest extends TestCase
{
    /**
     * @param array<string, list<int>>             $rows   table => existing ids
     * @param array<int, array<string, mixed>>     $stored id => stored row of the record being updated
     */
    private function subject(array $rows, array $stored = []): ParentProbeCommand
    {
        return new ParentProbeCommand($this->createMock(ContaoFramework::class), $rows, $stored);
    }

    private function refusal(ImageSizeUpdateCommand $subject, string $table, array $fields, ?int $excludeId = null): ?string
    {
        try {
            (new \ReflectionMethod($subject, 'refuseMissingParent'))->invoke($subject, $table, $fields, $excludeId);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_child']['config']      = ['ptable' => 'tl_parent'];
        $GLOBALS['TL_DCA']['tl_element']['config']    = ['ptable' => 'tl_article', 'dynamicPtable' => true];
        $GLOBALS['TL_DCA']['tl_tree']['config']       = [];
        $GLOBALS['TL_DCA']['tl_tree']['list']         = ['sorting' => ['mode' => 5]];
        $GLOBALS['TL_DCA']['tl_standalone']['config'] = [];
    }

    protected function tearDown(): void
    {
        foreach (['tl_child', 'tl_element', 'tl_tree', 'tl_standalone'] as $t) {
            unset($GLOBALS['TL_DCA'][$t]);
        }
    }

    public function testAnExistingParentPasses(): void
    {
        $this->assertNull($this->refusal($this->subject(['tl_parent' => [3]]), 'tl_child', ['pid' => 3, 'title' => 'x']));
    }

    public function testAMissingParentIsRefusedByName(): void
    {
        $message = $this->refusal($this->subject(['tl_parent' => [3]]), 'tl_child', ['pid' => 99999]);

        $this->assertNotNull($message);
        $this->assertStringContainsString('No tl_parent record with ID 99999', $message);
        $this->assertStringContainsString('Nothing was written', $message);
    }

    public function testZeroIsNoParentForAChildTable(): void
    {
        $this->assertNotNull($this->refusal($this->subject([]), 'tl_child', ['pid' => 0]));
    }

    public function testADynamicPtableIsFollowed(): void
    {
        $subject = $this->subject(['tl_article' => [5], 'tl_news' => [7]]);

        $this->assertNull($this->refusal($subject, 'tl_element', ['pid' => 5]));
        $this->assertNull($this->refusal($subject, 'tl_element', ['pid' => 7, 'ptable' => 'tl_news']));
        $this->assertNotNull($this->refusal($subject, 'tl_element', ['pid' => 5, 'ptable' => 'tl_news']));
    }

    public function testATreeAllowsTheTopLevelAndChecksItself(): void
    {
        $subject = $this->subject(['tl_tree' => [1]]);

        $this->assertNull($this->refusal($subject, 'tl_tree', ['pid' => 0]));
        $this->assertNull($this->refusal($subject, 'tl_tree', ['pid' => 1]));
        $this->assertStringContainsString('No tl_tree record with ID 2', (string) $this->refusal($subject, 'tl_tree', ['pid' => 2]));
    }

    public function testATableWithoutParentIsNotChecked(): void
    {
        $subject = $this->subject([]);

        $this->assertNull($this->refusal($subject, 'tl_standalone', ['pid' => 42]));
        $this->assertSame([], $subject->asked);
    }

    public function testNothingIsAskedWhenPidIsNotWritten(): void
    {
        $subject = $this->subject([]);

        $this->assertNull($this->refusal($subject, 'tl_child', ['title' => 'x'], 12));
        $this->assertSame([], $subject->asked);
    }

    public function testAnUpdateOfPtableAloneUsesTheStoredPid(): void
    {
        $subject = $this->subject(['tl_news' => [8]], [30 => ['pid' => 8, 'ptable' => 'tl_article']]);

        $this->assertNull($this->refusal($subject, 'tl_element', ['ptable' => 'tl_news'], 30));
        $this->assertSame(['tl_news.8'], $subject->asked);
    }

    public function testAMoveOnUpdateUsesTheStoredPtable(): void
    {
        $subject = $this->subject(['tl_news' => [8]], [30 => ['pid' => 8, 'ptable' => 'tl_news']]);

        $this->assertNotNull($this->refusal($subject, 'tl_element', ['pid' => 9], 30));
        $this->assertSame(['tl_news.9'], $subject->asked);
    }

    public function testAnEmptyPidIsZeroAndRefusedForAChildTable(): void
    {
        $subject = $this->subject(['tl_parent' => [3]]);

        $this->assertStringContainsString('No tl_parent record with ID 0', (string) $this->refusal($subject, 'tl_child', ['pid' => ''], 12));
        $this->assertNull($this->refusal($this->subject(['tl_tree' => [1]]), 'tl_tree', ['pid' => ''], 12));
    }

    public function testAnUnknownPtableIsNamedAsSuch(): void
    {
        $message = (string) $this->refusal($this->subject(['tl_article' => [5]]), 'tl_element', ['pid' => 5, 'ptable' => 'tl_newz']);

        $this->assertStringContainsString('There is no table tl_newz', $message);
    }

    public function testATreeRecordCannotMoveBelowItselfOrItsSubrecords(): void
    {
        // 1 > 2 > 3: moving 1 below 3 would detach the branch.
        $subject = $this->subject(['tl_tree' => [1, 2, 3, 4]], [1 => ['pid' => 0], 2 => ['pid' => 1], 3 => ['pid' => 2], 4 => ['pid' => 0]]);

        $this->assertStringContainsString('below itself', (string) $this->refusal($subject, 'tl_tree', ['pid' => 1], 1));
        $this->assertStringContainsString('below itself', (string) $this->refusal($subject, 'tl_tree', ['pid' => 3], 1));
        $this->assertNull($this->refusal($subject, 'tl_tree', ['pid' => 4], 1));
        $this->assertNull($this->refusal($subject, 'tl_tree', ['pid' => 1], 4));
    }

    public function testAGivenPidNeedsNoStoredRowOutsideDynamicPtables(): void
    {
        $subject = $this->subject(['tl_parent' => [3]]);

        $this->assertNull($this->refusal($subject, 'tl_child', ['pid' => 3], 12));
        $this->assertSame([], $subject->storedAsked);
    }

    public function testConvertFieldsCallsBothNewChecks(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');
        $start  = strpos($source, 'protected function convertFields(');
        $body   = substr($source, (int) $start, (int) strpos($source, "\n    }\n", (int) $start) - (int) $start);

        $this->assertStringContainsString('$this->refuseMissingParent(', $body);
        $this->assertStringContainsString('$this->refuseWidgetLimitViolations(', $body);
    }
}

/**
 * Answers the two database questions from arrays and records what was asked.
 */
class ParentProbeCommand extends ImageSizeUpdateCommand
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, list<int>>         $rows
     * @param array<int, array<string, mixed>> $stored
     */
    public function __construct(ContaoFramework $framework, private readonly array $rows, private readonly array $stored)
    {
        parent::__construct($framework);
    }

    protected function recordExists(string $table, int $id): bool
    {
        $this->asked[] = "$table.$id";

        return \in_array($id, $this->rows[$table] ?? [], true);
    }

    /** @var list<int|null> */
    public array $storedAsked = [];

    protected function storedRow(string $table, ?int $id): array
    {
        $this->storedAsked[] = $id;

        return $this->stored[$id] ?? [];
    }

    /** A table exists when the test names it among the rows. */
    protected function tableExists(string $table): bool
    {
        return \array_key_exists($table, $this->rows);
    }
}
