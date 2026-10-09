<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleMoveCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentMoveCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageMoveCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;

/**
 * `page move`, `article move` and `content move` — the back end's cut and paste.
 *
 * `--set pid=` could move a record since v0.27.0, but nobody looking for "move"
 * found it, and it could only put the record at the end. People say "put it
 * below X" or "after X": `--to` is the first, `--after` the second.
 *
 * The position follows `DC_Table::getNewPosition()` (identical in 5.3, 5.7, 6.0):
 *
 * | given | parent | sorting |
 * |---|---|---|
 * | `--to P` | P | behind the last child of P (`MAX + 128`) |
 * | `--after S` | the parent of S | halfway between S and the next sibling |
 * | `--after S`, S is last | the parent of S | `S + 128` |
 * | `--after S`, no integer between S and the next | the parent of S | the siblings renumbered in steps of 128, the record behind S |
 *
 * The write itself is the update command's: parent check, no move below itself,
 * the page tree rules, the URL rules, version, log, cache. Added in v1.2.0 after
 * Contao's 6.1 API gained the same operation (contao/contao#10400).
 */
class MoveCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['config']    = [];
        $GLOBALS['TL_DCA']['tl_content']['config'] = ['dynamicPtable' => true];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_page'], $GLOBALS['TL_DCA']['tl_content']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows id => stored row
     */
    private function probe(array $rows, ?int $max = null): PageMoveProbe
    {
        return new PageMoveProbe($this->createMock(ContaoFramework::class), $rows, $max);
    }

    /**
     * @return array<string, mixed>
     */
    private function after(PageMoveProbe $probe, string $table, int $id, int $sibling): array
    {
        /** @var array<string, mixed> */
        return (new \ReflectionMethod($probe, 'positionAfter'))->invoke($probe, $table, $id, $sibling);
    }

    /**
     * @return array<string, mixed>
     */
    private function atEnd(PageMoveProbe $probe, string $table, int $id, int $parent, string $ptable = ''): array
    {
        /** @var array<string, mixed> */
        return (new \ReflectionMethod($probe, 'positionAtEnd'))->invoke($probe, $table, $id, $parent, $ptable);
    }

    private const PAGES = [
        2  => ['id' => 2, 'pid' => 1, 'sorting' => 128],
        3  => ['id' => 3, 'pid' => 1, 'sorting' => 256],
        4  => ['id' => 4, 'pid' => 1, 'sorting' => 257],
        5  => ['id' => 5, 'pid' => 1, 'sorting' => 384],
        12 => ['id' => 12, 'pid' => 7, 'sorting' => 128],
    ];

    public function testToPutsTheRecordBehindTheLastChild(): void
    {
        $fields = $this->atEnd($this->probe(self::PAGES, 384), 'tl_page', 12, 1);

        $this->assertSame(['pid' => 1, 'sorting' => 512], $fields);
    }

    public function testToAnEmptyParentGivesTheFirstPosition(): void
    {
        $this->assertSame(['pid' => 9, 'sorting' => 128], $this->atEnd($this->probe(self::PAGES), 'tl_page', 12, 9));
    }

    public function testAfterGoesHalfwayToTheNextSibling(): void
    {
        $fields = $this->after($this->probe(self::PAGES), 'tl_page', 12, 2);

        $this->assertSame(['pid' => 1, 'sorting' => 192], $fields);
    }

    public function testAfterTheLastSiblingGoesBehindIt(): void
    {
        $this->assertSame(['pid' => 1, 'sorting' => 512], $this->after($this->probe(self::PAGES), 'tl_page', 12, 5));
    }

    public function testWithoutRoomTheSiblingsAreRenumbered(): void
    {
        $probe  = $this->probe(self::PAGES);
        $fields = $this->after($probe, 'tl_page', 12, 3);

        // 2, 3, [12], 4, 5 in steps of 128 — only the values that change are written
        $this->assertSame(['pid' => 1, 'sorting' => 384], $fields);
        $this->assertSame([4 => 512, 5 => 640], $probe->written);
    }

    public function testTheMovedRecordIsNotItsOwnNextSibling(): void
    {
        // 3 moves behind 2 within the same parent: the next sibling is 4, not 3 itself.
        $this->assertSame(['pid' => 1, 'sorting' => 192], $this->after($this->probe(self::PAGES), 'tl_page', 3, 2));
    }

    public function testARecordCannotGoAfterItself(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be moved behind itself');

        $this->after($this->probe(self::PAGES), 'tl_page', 3, 3);
    }

    public function testAMissingSiblingIsNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No tl_page record with ID 99');

        $this->after($this->probe(self::PAGES), 'tl_page', 3, 99);
    }

    /**
     * Review W1 (2026-10-09): the renumbering runs before the update's checks. With
     * the moved record left out of it, a refused move in the same parent kept its
     * old number while its neighbours got new ones — 10, 20, 30, 31 read 10, 30, 20,
     * 31 afterwards. Renumbered at its old place, it keeps the order either way.
     */
    public function testARefusedMoveInTheSameParentKeepsTheOrder(): void
    {
        $probe = $this->probe([
            10 => ['id' => 10, 'pid' => 1, 'sorting' => 128],
            20 => ['id' => 20, 'pid' => 1, 'sorting' => 256],
            30 => ['id' => 30, 'pid' => 1, 'sorting' => 384],
            31 => ['id' => 31, 'pid' => 1, 'sorting' => 385],
        ]);
        $fields = $this->after($probe, 'tl_page', 20, 30);

        // 10, 20, 30, [slot], 31 — the moved record keeps a number at its old place
        $this->assertSame(['pid' => 1, 'sorting' => 512], $fields);
        $this->assertSame([31 => 640], $probe->written);
    }

    public function testAMissingRecordRenumbersNothing(): void
    {
        $probe = $this->probe(self::PAGES);

        try {
            $this->after($probe, 'tl_page', 99, 3);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('No tl_page record with ID 99 to move', $e->getMessage());
        }

        $this->assertSame([], $probe->written);
    }

    /**
     * Review H3: `--ptable` goes with `--to`. With `--after` the element takes the
     * sibling's parent table, and a given one was silently ignored.
     */
    public function testPtableWithAfterIsRefused(): void
    {
        $output = $this->runCommand(new ContentMoveCommand($this->createMock(ContaoFramework::class)), ['id' => '12', '--after' => '5', '--ptable' => 'tl_news']);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('--ptable', $output['message']);
    }

    public function testAMoveTakesNoSet(): void
    {
        $output = $this->runCommand(new PageMoveCommand($this->createMock(ContaoFramework::class)), ['id' => '12', '--to' => '3', '--set' => ['title=x']]);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('no --set', $output['message']);
    }

    public function testExactlyOneOfToAndAfter(): void
    {
        foreach ([['id' => '12'], ['id' => '12', '--to' => '3', '--after' => '5']] as $input) {
            $output = $this->runCommand(new PageMoveCommand($this->createMock(ContaoFramework::class)), $input);

            $this->assertSame('error', $output['status']);
            $this->assertStringContainsString('either --to', $output['message']);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function runCommand(PageMoveCommand|ContentMoveCommand $command, array $input): array
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        /** @var array<string, mixed> */
        return json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testContentAfterASiblingTakesItsParentTable(): void
    {
        $probe  = $this->probe([
            20 => ['id' => 20, 'pid' => 8, 'ptable' => 'tl_news', 'sorting' => 128],
            30 => ['id' => 30, 'pid' => 5, 'ptable' => 'tl_article', 'sorting' => 64],
        ]);
        $fields = $this->after($probe, 'tl_content', 30, 20);

        $this->assertSame(['pid' => 8, 'sorting' => 256, 'ptable' => 'tl_news'], $fields);
        $this->assertSame(['siblings tl_content.8.tl_news'], $probe->asked);
    }

    public function testContentToKeepsItsParentTableUnlessGiven(): void
    {
        $probe = $this->probe([30 => ['id' => 30, 'pid' => 5, 'ptable' => 'tl_news', 'sorting' => 64]]);

        $this->assertSame(['pid' => 9, 'sorting' => 128], $this->atEnd($probe, 'tl_content', 30, 9));
        $this->assertSame(['pid' => 9, 'sorting' => 128, 'ptable' => 'tl_article'], $this->atEnd($probe, 'tl_content', 30, 9, 'tl_article'));
        $this->assertSame(['max tl_content.9.tl_news', 'max tl_content.9.tl_article'], $probe->asked);
    }

    /**
     * The move commands are the update commands with another entry point, so every
     * rule of the write path applies — the page tree and URL rules included.
     */
    public function testEveryMoveCommandIsItsUpdateCommand(): void
    {
        $this->assertTrue(is_subclass_of(PageMoveCommand::class, PageUpdateCommand::class));
        $this->assertTrue(is_subclass_of(ArticleMoveCommand::class, ArticleUpdateCommand::class));
        $this->assertTrue(is_subclass_of(ContentMoveCommand::class, ContentUpdateCommand::class));
    }

    public function testTheCommandsAreNamedAndHaveBothOptions(): void
    {
        foreach ([PageMoveCommand::class => 'page', ArticleMoveCommand::class => 'article', ContentMoveCommand::class => 'content'] as $class => $entity) {
            $command    = new $class($this->createMock(ContaoFramework::class));
            $definition = $command->getDefinition();

            $this->assertSame("contao:$entity:move", $command->getName());
            $this->assertTrue($definition->hasOption('to'), "$class --to");
            $this->assertTrue($definition->hasOption('after'), "$class --after");
            $this->assertFalse($definition->hasOption('ids'), "$class moves one record at a time");
            $this->assertSame('content' === $entity, $definition->hasOption('ptable'), "$class --ptable");
        }
    }
}

class PageMoveProbe extends PageMoveCommand
{
    /** @var list<string> */
    public array $asked = [];

    /** @var array<int, int> id => sorting */
    public array $written = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(ContaoFramework $framework, private readonly array $rows, private readonly ?int $max)
    {
        parent::__construct($framework);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return $this->rows[$id] ?? [];
    }

    protected function maxSorting(string $table, int $pid, ?string $ptable): ?int
    {
        $this->asked[] = "max $table.$pid.$ptable";

        return $this->max;
    }

    /** As the production query: the ptable filter only when one is given (review H4). */
    protected function siblingsInOrder(string $table, int $pid, string $ptable): array
    {
        $this->asked[] = "siblings $table.$pid.$ptable";
        $siblings      = [];

        foreach ($this->rows as $id => $row) {
            if ((int) $row['pid'] === $pid && ('' === $ptable || (string) ($row['ptable'] ?? '') === $ptable)) {
                $siblings[$id] = (int) $row['sorting'];
            }
        }

        asort($siblings);

        return $siblings;
    }

    protected function writeSorting(string $table, int $id, int $sorting): void
    {
        $this->written[$id] = $sorting;
    }
}
