<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * Every rejected argument has to come back as a structured error.
 *
 * The command validates three things against the DCA — the sort clause, the
 * filters and the requested columns — and each raises the same
 * \InvalidArgumentException. Two of the three calls were wrapped in a catch
 * that turned it into `{"status":"error","message":...}`; resolveColumns() was
 * not. An unknown column in --fields therefore escaped as an uncaught PHP
 * exception: a stack trace on stderr, nothing on stdout, while the identical
 * mistake in --order or --filter answered properly.
 *
 * Found live on 2026-08-31 against c5, not in review — the two guarded paths
 * read as if all three were covered.
 *
 * It matters beyond the CLI: RecordListTool hands the same failure to the
 * browser chat, so a model that guessed a column name got a stack trace where
 * it needed the sentence "unknown column".
 */
class RecordListErrorPathTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function tester(): CommandTester
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`'
        );
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchOne')->willReturn(0);

        return new CommandTester(new RecordListCommand(
            $this->createMock(ContaoFramework::class),
            $connection,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommand(array $input): array
    {
        $tester = $this->tester();
        $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded, 'The command must answer with JSON, not a stack trace.');

        return $decoded;
    }

    public function testAnUnknownFieldIsRejectedWithAMessage(): void
    {
        $this->skipWithoutContaoContainer();

        $out = $this->runCommand(['table' => 'tl_page', '--fields' => 'gibtsnicht']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('gibtsnicht', $out['message']);
    }

    public function testAnUnknownOrderColumnIsRejectedWithAMessage(): void
    {
        $this->skipWithoutContaoContainer();

        $out = $this->runCommand(['table' => 'tl_page', '--order' => 'gibtsnicht DESC']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('gibtsnicht', $out['message']);
    }

    public function testAnUnknownFilterColumnIsRejectedWithAMessage(): void
    {
        $this->skipWithoutContaoContainer();

        $out = $this->runCommand(['table' => 'tl_page', '--filter' => ['gibtsnicht=1']]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('gibtsnicht', $out['message']);
    }

    /**
     * No container needed: the table name is checked by a regex before the DCA
     * is ever loaded.
     */
    public function testAnInvalidTableNameIsRejectedBeforeAnythingIsLoaded(): void
    {
        $out = $this->runCommand(['table' => 'not_a_contao_table']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not_a_contao_table', $out['message']);
    }
}
