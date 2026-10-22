<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand;

/**
 * `--after <sibling>` on the create commands (v1.12.0): the new record goes directly
 * behind the sibling, below its parent — the rule `move --after` uses
 * (`SiblingPositions::slotAfter()`, after `DC_Table::getNewPosition()`). Until then a
 * new element between two others meant computing `--set sorting=` by hand.
 *
 * The write itself (Model save, version) needs Contao and was checked on the test server.
 */
class CreateAfterTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, mixed>
     */
    private function create(string $class, array $input): array
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new $class($framework));
        $tester->execute($input);

        /** @var array<string, mixed> */
        return json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEveryCreateCommandHasAfter(): void
    {
        foreach ([ContentCreateCommand::class, ArticleCreateCommand::class, PageCreateCommand::class] as $class) {
            self::assertTrue((new $class($this->createMock(ContaoFramework::class)))->getDefinition()->hasOption('after'), $class);
        }
    }

    public function testAfterTakesTheSiblingsParentSoPidIsRefusedBeforeAnythingIsRead(): void
    {
        $answer = $this->create(ContentCreateCommand::class, ['--type' => 'text', '--after' => '5', '--pid' => '3']);
        self::assertSame('error', $answer['status']);
        self::assertStringContainsString('leave out --pid', $answer['message']);

        $answer = $this->create(ContentCreateCommand::class, ['--type' => 'text', '--after' => '5', '--ptable' => 'tl_news']);
        self::assertStringContainsString('leave out --ptable', $answer['message']);

        self::assertStringContainsString('leave out --pid', $this->create(ArticleCreateCommand::class, ['--title' => 'A', '--after' => '5', '--pid' => '3'])['message']);
        self::assertStringContainsString('leave out --pid', $this->create(PageCreateCommand::class, ['--title' => 'P', '--after' => '5', '--pid' => '0'])['message']);
    }

    public function testAfterMustBeAnId(): void
    {
        self::assertStringContainsString('--after must be a record ID', $this->create(ContentCreateCommand::class, ['--type' => 'text', '--after' => 'abc'])['message']);
        self::assertStringContainsString('--after must be a record ID', $this->create(PageCreateCommand::class, ['--title' => 'P', '--after' => '0'])['message']);
    }

    public function testWithoutPidOrAfterTheElementIsRefused(): void
    {
        self::assertStringContainsString('--pid (or --after', $this->create(ContentCreateCommand::class, ['--type' => 'text'])['message']);
        self::assertStringContainsString('--pid (or --after', $this->create(ArticleCreateCommand::class, ['--title' => 'A'])['message']);
    }

    /**
     * The slot for a new record: halfway to the next sibling; behind the last one; or
     * the siblings renumbered when no integer is left — and the sibling's parent table.
     */
    public function testTheSlotOfANewRecordFollowsTheBackEndsPaste(): void
    {
        $probe = new ContentCreateAfterProbe($this->createMock(ContaoFramework::class), [
            10 => ['id' => 10, 'pid' => 7, 'ptable' => 'tl_news', 'sorting' => 128],
            11 => ['id' => 11, 'pid' => 7, 'ptable' => 'tl_news', 'sorting' => 256],
            12 => ['id' => 12, 'pid' => 7, 'ptable' => 'tl_news', 'sorting' => 257],
        ]);
        $slot = static fn (int $sibling): array => (new \ReflectionMethod($probe, 'slotAfter'))->invoke($probe, 'tl_content', $sibling, null, 'put the new element');

        self::assertSame(['pid' => 7, 'sorting' => 192, 'ptable' => 'tl_news'], $slot(10));
        self::assertSame(['pid' => 7, 'sorting' => 385, 'ptable' => 'tl_news'], $slot(12));
        self::assertSame([], $probe->written);

        self::assertSame(['pid' => 7, 'sorting' => 384, 'ptable' => 'tl_news'], $slot(11));
        self::assertSame([12 => 512], $probe->written, 'only the sibling behind the slot moves up');
        self::assertSame(['tl_content'], $probe->loaded, 'the DCA is loaded, not assumed');
    }

    /**
     * Through doExecute() up to the record about to be saved (review v1.12.0: a mutation
     * that took pid and ptable from the options again passed every test). The probe stops
     * in preparedFields() with what the command hands it.
     *
     * @param array<string, string> $input
     *
     * @return array<string, mixed> the record's own fields
     */
    private function prepared(string $class, array $input): array
    {
        $framework = $this->createMock(ContaoFramework::class);
        $probe = new $class($framework, [
            11 => ['id' => 11, 'pid' => 7, 'ptable' => 'tl_news', 'sorting' => 256, 'inColumn' => 'left'],
            12 => ['id' => 12, 'pid' => 7, 'ptable' => 'tl_news', 'sorting' => 512, 'inColumn' => 'left'],
        ]);
        (new CommandTester($probe))->execute($input);
        self::assertNotNull($probe->own, 'reached preparedFields');

        return $probe->own;
    }

    public function testContentCreateTakesParentTableAndPositionFromTheSibling(): void
    {
        $own = $this->prepared(ContentCreatePrepareProbe::class, ['--type' => 'text', '--after' => '11']);

        self::assertSame([7, 'tl_news', 384], [$own['pid'], $own['ptable'], $own['sorting']]);
    }

    public function testArticleCreateTakesPageColumnAndPositionFromTheSibling(): void
    {
        $own = $this->prepared(ArticleCreatePrepareProbe::class, ['--title' => 'A', '--after' => '11']);
        self::assertSame([7, 384, 'left'], [$own['pid'], $own['sorting'], $own['inColumn']]);

        $own = $this->prepared(ArticleCreatePrepareProbe::class, ['--title' => 'A', '--after' => '11', '--inColumn' => 'main']);
        self::assertSame('main', $own['inColumn'], 'a column given wins');
    }

    public function testPageCreateTakesParentAndPositionFromTheSibling(): void
    {
        $own = $this->prepared(PageCreatePrepareProbe::class, ['--title' => 'P', '--after' => '11']);

        self::assertSame([7, 384], [$own['pid'], $own['sorting']]);
    }

    public function testSetFieldsThatWouldOverrideTheSlotAreRefused(): void
    {
        foreach (['pid=9', 'ptable=tl_article', 'sorting=1'] as $pair) {
            $answer = $this->create(ContentCreateCommand::class, ['--type' => 'text', '--after' => '11', '--set' => [$pair]]);
            self::assertStringContainsString('leave out --set ' . strstr($pair, '=', true), $answer['message'], $pair);
        }
        self::assertStringContainsString('--after needs a record ID', $this->create(PageCreateCommand::class, ['--title' => 'P', '--after' => ''])['message']);
    }

    public function testAMissingSiblingIsSaidWithoutWriting(): void
    {
        $probe = new ContentCreateAfterProbe($this->createMock(ContaoFramework::class), []);

        try {
            (new \ReflectionMethod($probe, 'slotAfter'))->invoke($probe, 'tl_content', 99, null, 'put the new element');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('No tl_content record with ID 99 to put the new element behind', $e->getMessage());
            self::assertSame([], $probe->written);
        }
    }
}

/**
 * Rows in memory instead of the database, and a stop right before the save: what the
 * command hands preparedFields() as the record's own fields.
 */
trait StopsBeforeTheSave
{
    /** @var array<string, mixed>|null */
    public ?array $own = null;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(ContaoFramework $framework, private readonly array $rows)
    {
        parent::__construct($framework);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return $this->rows[$id] ?? [];
    }

    protected function siblingsInOrder(string $table, int $pid, string $ptable): array
    {
        $siblings = [];
        foreach ($this->rows as $id => $row) {
            if ((int) $row['pid'] === $pid) {
                $siblings[$id] = (int) $row['sorting'];
            }
        }

        return $siblings;
    }

    protected function writeSorting(string $table, int $id, int $sorting): void
    {
    }

    protected function loadDca(string $table): void
    {
        $GLOBALS['TL_DCA'][$table]['config'] = ['dynamicPtable' => 'tl_content' === $table];
    }

    protected function resolveAlias(string $table, string $given, string $from, string $field = 'alias', array $record = []): string
    {
        return 'alias';
    }

    protected function resolveAuthorId(): int
    {
        return 1;
    }

    /** Needs Contao's DCA loader; the headline is not what is tested here. */
    protected function convertInputUnitFields(string $table, array $fields, ?string $defaultUnit = null, ?object $record = null): array
    {
        return $fields;
    }

    protected function preparedFields(string $table, array $own, array $set, ?int $excludeId = null): array
    {
        $this->own = $own;

        throw new \RuntimeException('stopped before the save');
    }
}

class ContentCreatePrepareProbe extends ContentCreateCommand
{
    use StopsBeforeTheSave;
}

class ArticleCreatePrepareProbe extends ArticleCreateCommand
{
    use StopsBeforeTheSave;
}

class PageCreatePrepareProbe extends PageCreateCommand
{
    use StopsBeforeTheSave;
}

/**
 * The create command with the database replaced by rows in memory.
 */
class ContentCreateAfterProbe extends ContentCreateCommand
{
    /** @var array<int, int> */
    public array $written = [];

    /** @var list<string> */
    public array $loaded = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(ContaoFramework $framework, private readonly array $rows)
    {
        parent::__construct($framework);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return $this->rows[$id] ?? [];
    }

    protected function siblingsInOrder(string $table, int $pid, string $ptable): array
    {
        $siblings = [];
        foreach ($this->rows as $id => $row) {
            if ((int) $row['pid'] === $pid && (string) $row['ptable'] === $ptable) {
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

    protected function loadDca(string $table): void
    {
        $this->loaded[] = $table;
        $GLOBALS['TL_DCA'][$table]['config'] = ['dynamicPtable' => true];
    }
}
