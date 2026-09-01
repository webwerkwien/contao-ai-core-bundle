<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractPresenter;

/**
 * A reader has to be able to tell a check from a claim.
 *
 * The objection that shaped the design, from the ww-buchung session: *"a
 * declaration nobody verifies is an assertion."* Flattening the three classes
 * into one object would produce exactly the failure this project keeps meeting
 * — "the command writes a version" and "the command claims it writes a
 * version" are not the same sentence, and a caller cannot recover the
 * difference afterwards.
 */
class ContractPresenterTest extends TestCase
{
    private static function present(array $fields, array $problems = [], array $withDca = []): array
    {
        return ContractPresenter::present(
            ['fields' => $fields, 'problems' => $problems],
            static fn (string $table): bool => \in_array($table, $withDca, true),
        );
    }

    public function testAClaimNeverAppearsBesideACheck(): void
    {
        $out = self::present([
            'tables'       => ['tl_news'],
            'irreversible' => 'sends a mail',
        ], withDca: ['tl_news']);

        self::assertArrayHasKey('tl_news', array_flip($out['checked']['tables']));
        self::assertArrayNotHasKey('irreversible_outside_database', $out['checked']);
        self::assertSame('sends a mail', $out['declared']['irreversible_outside_database']);
    }

    public function testTheClaimsSayThatNobodyCanConfirmThem(): void
    {
        $out = self::present(['irreversible' => 'sends a mail']);

        self::assertStringContainsString('own word', $out['declared_note']);
    }

    public function testNoDeclaredBlockWhenNothingWasClaimed(): void
    {
        /* An empty "declared: {}" invites the reading that the command claimed
           nothing irreversible. It claimed nothing at all, which is different. */
        $out = self::present(['tables' => ['tl_news']], withDca: ['tl_news']);

        self::assertArrayNotHasKey('declared', $out);
        self::assertArrayNotHasKey('declared_note', $out);
    }

    public function testATableWithoutADcaIsNamedRatherThanDropped(): void
    {
        $out = self::present(['tables' => ['tl_news', 'tl_typo']], withDca: ['tl_news']);

        self::assertSame(['tl_news'], $out['checked']['tables_with_dca']);
        self::assertSame(['tl_typo'], $out['checked']['tables_without_dca']);
    }

    public function testTheTwoTraceTimingsGetOppositeNotes(): void
    {
        $before = self::present(['trace' => ['tl_log'], 'traceWhen' => 'before']);
        $after  = self::present(['trace' => ['tl_log'], 'traceWhen' => 'on-success']);

        self::assertStringContainsString('still leaves the record', $before['checked_with_statement']['trace_when_note']);
        self::assertStringContainsString('no trace of having started', $after['checked_with_statement']['trace_when_note']);
    }

    public function testProblemsTravelWithTheContractInsteadOfReplacingIt(): void
    {
        /* Half a contract that says which half is missing beats no contract. */
        $out = self::present(['tables' => ['tl_news']], problems: ['trace must be a list'], withDca: ['tl_news']);

        self::assertSame(['trace must be a list'], $out['problems']);
        self::assertSame(['tl_news'], $out['checked']['tables']);
    }
}
