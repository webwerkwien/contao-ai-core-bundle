<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A `--set` field that is not a column is refused, not reported as written.
 *
 * `--set gibtesnicht=1` answered `{"status":"ok","updated":["gibtesnicht"]}`.
 * Nothing was written — `Model::save()` filters `arrModified` against
 * `Database::getFieldNames()` and drops what is not a column — but
 * `ModelWriter::update()` reported back the names it had been *given*.
 *
 * 🎯 **A silent no-op that reports success is the failure this project keeps
 * hunting.** Same shape as the bulk run of 2026-08-29 (174 IDs in, one record
 * changed, "0 failed") and the pipx no-op of v0.4.3. A wrong answer that looks
 * like an answer is worse than an error, because nobody goes looking.
 *
 * Refusing rather than reporting truthfully, because the read side already
 * does: `contao:record:list` validates `--fields`, `--filter` and `--order`
 * against the DCA and refuses the rest. Writing should not be the looser of
 * the two.
 */
class UnknownFieldRefusalTest extends TestCase
{
    /**
     * @param list<string> $columns
     */
    private function subject(array $columns): ImageSizeUpdateCommand
    {
        return new class ($this->createMock(ContaoFramework::class), $columns) extends ImageSizeUpdateCommand {
            /**
             * @param list<string> $columns
             */
            public function __construct(ContaoFramework $framework, private readonly array $columns)
            {
                parent::__construct($framework);
            }

            protected function tableColumns(string $table): array
            {
                return $this->columns;
            }
        };
    }

    /**
     * @param list<string>         $columns
     * @param array<string, mixed> $fields
     */
    private function check(array $columns, array $fields): void
    {
        $method = new \ReflectionMethod($this->subject($columns), 'refuseUnknownFields');
        $method->invoke($this->subject($columns), 'tl_test', $fields);
    }

    public function testAKnownColumnPasses(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['id', 'title', 'published'], ['title' => 'x']);
    }

    public function testAnUnknownColumnIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/gibtesnicht/');

        $this->check(['id', 'title'], ['gibtesnicht' => '1']);
    }

    public function testTheMessageNamesEveryUnknownFieldAndTheTable(): void
    {
        try {
            $this->check(['id', 'title'], ['title' => 'x', 'foo' => '1', 'bar' => '2']);
            $this->fail('Expected the unknown fields to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tl_test', $e->getMessage());
            $this->assertStringContainsString('foo', $e->getMessage());
            $this->assertStringContainsString('bar', $e->getMessage());
            $this->assertStringNotContainsString('title', $e->getMessage());
        }
    }

    /**
     * Saying so matters more than the refusal itself: the caller has to know
     * that the previous "ok" meant nothing, not that it half-worked.
     */
    public function testTheMessageSaysNothingWasWritten(): void
    {
        try {
            $this->check(['id'], ['foo' => '1']);
            $this->fail('Expected the unknown field to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Nothing was written', $e->getMessage());
        }
    }

    public function testNoFieldsIsNotAnError(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['id', 'title'], []);
    }

    /**
     * ⚠️ An unreachable database must not turn into a validation message.
     * `tableColumns()` answers `[]` when it cannot ask, and the write then
     * fails on its own terms and says what actually went wrong.
     */
    public function testAnEmptyColumnListSkipsTheCheckRatherThanRefusingEverything(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check([], ['anything' => '1']);
    }

    /**
     * The check has to sit in the shared entry point, or it only guards the
     * commands that happen to call it — the failure CreateCommandConversionTest
     * was written for.
     */
    public function testTheSharedEntryPointRunsIt(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');

        $this->assertMatchesRegularExpression(
            '/function convertFields\(.*?refuseUnknownFields\(/s',
            $source,
            'convertFields() no longer refuses unknown fields, so a typo in a field name '
            . 'reports success again while changing nothing.',
        );
    }
}
