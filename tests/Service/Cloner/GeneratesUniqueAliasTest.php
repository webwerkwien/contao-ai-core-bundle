<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\GeneratesUniqueAlias;

/**
 * Pins H-6 from the audit on 2026-09-02.
 *
 * The cloners generated a child alias with `StringUtil::generateAlias()` and
 * saved it. `Contao\Model::save()` does not run the DCA `save_callback`, so
 * Contao's own uniqueness check never ran — cloning a news item called
 * *Sommerfest* produced a second row with the alias `sommerfest`, and which of
 * the two an alias lookup reaches is a matter of row order.
 *
 * 🎯 Contao states the rule in its own DCA, for all three affected tables:
 *
 *     'eval' => array('rgxp'=>'alias', 'doNotCopy'=>true, 'unique'=>true, …)
 *
 * `doNotCopy` says outright that the value must not survive a duplication —
 * which is what a clone is.
 */
class GeneratesUniqueAliasTest extends TestCase
{
    /**
     * @param list<string> $taken aliases the table already holds
     */
    private function subject(array $taken): object
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params) => \in_array($params[0], $taken, true) ? 1 : false
        );

        return new class ($connection) {
            use GeneratesUniqueAlias;

            public function __construct(protected readonly Connection $connection)
            {
            }

            /** @param string $table */
            public function make(string $table, string $from, string $prefix = 'kopie'): string
            {
                return $this->uniqueAlias($table, $from, $prefix);
            }
        };
    }

    public function testAFreeAliasIsUsedUnchanged(): void
    {
        self::assertSame('sommerfest', $this->subject([])->make('tl_news', 'Sommerfest'));
    }

    public function testATakenAliasGetsACountedSuffix(): void
    {
        // Contao counts up through its slug service; this does the same with the
        // tools a cloner has. A random suffix would only collide by accident,
        // and "only by accident" is not a check.
        self::assertSame('sommerfest-2', $this->subject(['sommerfest'])->make('tl_news', 'Sommerfest'));
    }

    public function testItKeepsCountingUntilOneIsFree(): void
    {
        $subject = $this->subject(['sommerfest', 'sommerfest-2', 'sommerfest-3']);

        self::assertSame('sommerfest-4', $subject->make('tl_news', 'Sommerfest'));
    }

    public function testContaoAlreadyPrefixesANumericAlias(): void
    {
        // Not our guard: `StringUtil::generateAlias('2026')` returns `id-2026`.
        // This test started life asserting a prefix of our own, failed, and
        // thereby found dead code in the fix it was written for — a check that
        // could never fire. Contao refuses numeric aliases (ERR.aliasNumeric),
        // and it also makes sure none is produced.
        self::assertSame('id-2026', $this->subject([])->make('tl_news', '2026'));
    }

    public function testAnEmptySourceFallsBackToThePrefix(): void
    {
        $alias = $this->subject([])->make('tl_faq', '   ', 'faq');

        self::assertStringStartsWith('faq-', $alias);
        self::assertNotSame('faq-', $alias, 'the fallback must carry something distinguishing');
    }

    public function testItGivesUpCountingRatherThanLoopingForever(): void
    {
        // Every counted suffix taken. A clone loop that cannot terminate is worse
        // than a clone that produces an ugly alias, so it falls back to something
        // that cannot collide — and still never writes a duplicate.
        $taken = ['sommerfest'];
        for ($i = 2; $i <= 60; ++$i) {
            $taken[] = 'sommerfest-' . $i;
        }

        $alias = $this->subject($taken)->make('tl_news', 'Sommerfest');

        self::assertStringStartsWith('sommerfest-', $alias);
        self::assertNotContains($alias, $taken, 'it returned an alias that is already taken');
    }

    public function testTheTableNameIsQuoted(): void
    {
        // The cloners pass constants, so this is defence in depth rather than a
        // live hole — but an unquoted identifier in a hand-built query is the
        // kind of thing that stops being safe when someone adds a parameter.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('quoteIdentifier')->with('tl_news')->willReturn('`tl_news`');
        $connection->method('fetchOne')->willReturn(false);

        $subject = new class ($connection) {
            use GeneratesUniqueAlias;

            public function __construct(protected readonly Connection $connection)
            {
            }

            public function make(): string
            {
                return $this->uniqueAlias('tl_news', 'Sommerfest');
            }
        };

        $subject->make();
    }
}
