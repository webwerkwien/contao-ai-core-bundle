<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentCopyCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\RecordCopier;

/**
 * `content copy`: the back end's copy and paste. Where the copy goes is decided here,
 * the copy itself is RecordCopier's (RecordCopierTest).
 *
 * | given | parent | sorting |
 * |---|---|---|
 * | nothing | the source's | directly behind the source |
 * | `--after S` | the parent of S | directly behind S |
 * | `--to P` | P, ptable the source's unless `--ptable` | behind the last child of P |
 */
class CopyCommandTest extends TestCase
{
    private const ROWS = [
        40 => ['id' => 40, 'pid' => 7, 'ptable' => 'tl_article', 'sorting' => 128],
        42 => ['id' => 42, 'pid' => 40, 'ptable' => 'tl_content', 'sorting' => 128],
        43 => ['id' => 43, 'pid' => 42, 'ptable' => 'tl_content', 'sorting' => 128],
        44 => ['id' => 44, 'pid' => 7, 'ptable' => 'tl_article', 'sorting' => 256],
    ];

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['config'] = ['dynamicPtable' => true];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    /**
     * @param array<string, mixed>|null $expected the overrides the copier must get; null: never called
     * @param array<string, mixed>      $input
     *
     * @return array<string, mixed> the decoded answer
     */
    private function copy(?array $expected, array $input, ?int $max = 512): array
    {
        $copier = $this->createMock(RecordCopier::class);

        if (null === $expected) {
            $copier->expects(self::never())->method('copy');
        } else {
            $copier->expects(self::once())->method('copy')
                ->with('tl_content', (int) $input['id'], $expected, 'k.editor', 'de')
                ->willReturn(['id' => 100, 'children' => 2, 'notCopied' => []]);
        }

        $command = new ContentCopyProbe($this->createMock(ContaoFramework::class), $copier, self::ROWS, $max, ['tl_article.9']);
        $command->setLogger(new NullLogger());

        $tester = new CommandTester($command);
        $tester->execute($input + ['--operator' => 'k.editor']);

        /** @var array<string, mixed> */
        return json_decode(trim($tester->getDisplay()), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testWithoutATargetTheCopyGoesDirectlyBehindTheSource(): void
    {
        $answer = $this->copy(['pid' => 7, 'ptable' => 'tl_article', 'sorting' => 192], ['id' => '40']);

        self::assertSame('ok', $answer['status']);
        self::assertSame([100, 40, 7, 'tl_article', 192, 2], [$answer['id'], $answer['sourceId'], $answer['pid'], $answer['ptable'], $answer['sorting'], $answer['children']]);
        self::assertSame([], $answer['notCopied']);
    }

    public function testAfterASibling(): void
    {
        // 44 is the last of article 7: 256 + 128.
        $this->copy(['pid' => 7, 'ptable' => 'tl_article', 'sorting' => 384], ['id' => '40', '--after' => '44']);
    }

    public function testToAParentTakesTheSourcesParentTable(): void
    {
        $this->copy(['pid' => 9, 'ptable' => 'tl_article', 'sorting' => 640], ['id' => '40', '--to' => '9']);
    }

    public function testToAnotherParentTable(): void
    {
        $answer = $this->copy(['pid' => 44, 'ptable' => 'tl_content', 'sorting' => 640], ['id' => '40', '--to' => '44', '--ptable' => 'tl_content']);

        self::assertSame('tl_content', $answer['ptable']);
    }

    public function testNotIntoItselfNorIntoOneOfItsElements(): void
    {
        foreach (['40', '42', '43'] as $into) {
            $answer = $this->copy(null, ['id' => '40', '--to' => $into, '--ptable' => 'tl_content']);

            self::assertSame('error', $answer['status']);
            self::assertStringContainsString('cannot be copied into itself', (string) $answer['message']);
        }
    }

    public function testAMissingParentIsRefused(): void
    {
        $answer = $this->copy(null, ['id' => '40', '--to' => '8']);

        self::assertSame('error', $answer['status']);
        self::assertStringContainsString('No tl_article record with ID 8', (string) $answer['message']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'missing source' => [['id' => '41'], 'Content element not found: 41'];
        yield 'no ID' => [['id' => 'x'], 'Give the ID of the content element to copy'];
        yield 'to and after' => [['id' => '40', '--to' => '9', '--after' => '44'], 'not both'];
        yield 'ptable with after' => [['id' => '40', '--after' => '44', '--ptable' => 'tl_news'], '--ptable goes with --to'];
        yield 'set' => [['id' => '40', '--set' => ['cssID=x']], 'takes no --set'];
        yield 'to not an ID' => [['id' => '40', '--to' => 'abc'], '--to must be a record ID'];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @dataProvider refusals
     */
    public function testRefusalsWriteNothing(array $input, string $message): void
    {
        $answer = $this->copy(null, $input);

        self::assertSame('error', $answer['status']);
        self::assertStringContainsString($message, (string) $answer['message']);
    }
}

class ContentCopyProbe extends ContentCopyCommand
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string>                     $existing "table.id" of the parents that exist
     */
    public function __construct(ContaoFramework $framework, RecordCopier $copier, private readonly array $rows, private readonly ?int $max, private readonly array $existing)
    {
        parent::__construct($framework, $copier);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return $this->rows[$id] ?? [];
    }

    protected function maxSorting(string $table, int $pid, ?string $ptable): ?int
    {
        return $this->max;
    }

    protected function siblingsInOrder(string $table, int $pid, string $ptable): array
    {
        $siblings = [];

        foreach ($this->rows as $id => $row) {
            if ((int) $row['pid'] === $pid && ('' === $ptable || (string) $row['ptable'] === $ptable)) {
                $siblings[$id] = (int) $row['sorting'];
            }
        }

        asort($siblings);

        return $siblings;
    }

    protected function writeSorting(string $table, int $id, int $sorting): void
    {
    }

    protected function tableExists(string $table): bool
    {
        return true;
    }

    protected function recordExists(string $table, int $id): bool
    {
        return \in_array("$table.$id", $this->existing, true) || isset($this->rows[$id]) && 'tl_content' === $table;
    }

    // The operator's back-end language marks the copy, on the console too.
    protected function resolveOperatorLanguage(): ?string
    {
        return 'de';
    }
}
