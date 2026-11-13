<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleCopyCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\RecordCopier;

/**
 * `article copy`: the same targets without a parent table. Contao resets `author`
 * to the acting user (its closure default); `alias` and `published` the copier
 * resets from the DCA, the title it marks.
 */
class ArticleCopyCommandTest extends TestCase
{
    private const ROWS = [
        7 => ['id' => 7, 'pid' => 3, 'sorting' => 128, 'title' => 'Team'],
        8 => ['id' => 8, 'pid' => 3, 'sorting' => 256, 'title' => 'Kontakt'],
    ];

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_article']['config'] = ['ptable' => 'tl_page'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    /**
     * @param array<string, mixed>|null $expected
     * @param array<string, mixed>      $input
     *
     * @return array<string, mixed>
     */
    private function copy(?array $expected, array $input): array
    {
        $copier = $this->createMock(RecordCopier::class);

        if (null === $expected) {
            $copier->expects(self::never())->method('copy');
        } else {
            $copier->expects(self::once())->method('copy')
                ->with('tl_article', (int) $input['id'], $expected, 'k.editor', null)
                ->willReturn(['id' => 100, 'children' => 3, 'notCopied' => ['alias', 'author', 'published']]);
        }

        $command = new ArticleCopyProbe($this->createMock(ContaoFramework::class), $copier, self::ROWS);
        $command->setLogger(new NullLogger());

        $tester = new CommandTester($command);
        $tester->execute($input + ['--operator' => 'k.editor']);

        /** @var array<string, mixed> */
        return json_decode(trim($tester->getDisplay()), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testBehindTheSourceAndOwnedByTheActingUser(): void
    {
        $answer = $this->copy(['pid' => 3, 'sorting' => 192, 'author' => 5], ['id' => '7']);

        self::assertSame('ok', $answer['status']);
        self::assertArrayNotHasKey('ptable', $answer);
        self::assertSame(['alias', 'author', 'published'], $answer['notCopied']);
    }

    public function testToAnotherPage(): void
    {
        $this->copy(['pid' => 4, 'sorting' => 640, 'author' => 5], ['id' => '7', '--to' => '4']);
    }

    public function testAfterAnArticle(): void
    {
        $this->copy(['pid' => 3, 'sorting' => 384, 'author' => 5], ['id' => '7', '--after' => '8']);
    }

    public function testAMissingPageIsRefused(): void
    {
        $answer = $this->copy(null, ['id' => '7', '--to' => '99']);

        self::assertSame('error', $answer['status']);
        self::assertStringContainsString('No tl_page record with ID 99', (string) $answer['message']);
    }

    public function testAnArticleHasNoParentTableOption(): void
    {
        self::assertFalse((new ArticleCopyProbe($this->createMock(ContaoFramework::class), $this->createMock(RecordCopier::class), []))->getDefinition()->hasOption('ptable'));
    }
}

class ArticleCopyProbe extends ArticleCopyCommand
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(ContaoFramework $framework, RecordCopier $copier, private readonly array $rows)
    {
        parent::__construct($framework, $copier);
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return $this->rows[$id] ?? [];
    }

    protected function maxSorting(string $table, int $pid, ?string $ptable): ?int
    {
        return 512;
    }

    protected function siblingsInOrder(string $table, int $pid, string $ptable): array
    {
        $siblings = [];

        foreach ($this->rows as $id => $row) {
            if ((int) $row['pid'] === $pid) {
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
        return 'tl_page' === $table && \in_array($id, [3, 4], true);
    }

    protected function resolveAuthorId(): int
    {
        return 5;
    }

    // An operator without a back-end language: the copier keeps the loaded mark.
    protected function resolveOperatorLanguage(): ?string
    {
        return null;
    }
}
