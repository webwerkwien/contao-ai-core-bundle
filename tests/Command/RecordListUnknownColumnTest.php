<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;

/**
 * An unknown column is answered with the columns that would have worked.
 *
 * "unknown column: status" said what was wrong and nothing about what was
 * right, so the second attempt was another guess. The message now names the
 * likely candidates and every column the option accepts.
 */
class RecordListUnknownColumnTest extends TestCase
{
    private const COLUMNS = ['id', 'pid', 'tstamp', 'orderStatus', 'providerReference', 'secret'];

    private function command(): RecordListCommand
    {
        $command = new RecordListCommand(
            $this->createMock(ContaoFramework::class),
            $this->createMock(Connection::class),
        );
        (new \ReflectionProperty($command, 'sensitive'))->setValue($command, ['secret']);

        return $command;
    }

    private function message(string $option, string $column): string
    {
        $command = $this->command();
        $method  = new \ReflectionMethod($command, 'unknownColumn');

        return $method->invoke($command, $option, $column, self::COLUMNS)->getMessage();
    }

    /**
     * @return list<string>
     */
    private function similar(string $column): array
    {
        $method = new \ReflectionMethod(RecordListCommand::class, 'similarColumns');

        return $method->invoke(null, $column, self::COLUMNS);
    }

    public function testAPartOfTheNameFindsTheColumn(): void
    {
        $this->assertSame(['orderStatus'], $this->similar('status'));
        $this->assertSame(['providerReference'], $this->similar('reference'));
    }

    /**
     * MySQL ignores the case of a column name, the command's whitelist does not.
     */
    public function testTheSameNameInAnotherCaseComesFirst(): void
    {
        $this->assertSame(['orderStatus'], $this->similar('orderstatus'));
    }

    public function testATypoFindsTheColumn(): void
    {
        $this->assertSame(['tstamp'], $this->similar('tstmap'));
    }

    /**
     * Two characters are too few to tell a typo from a different column:
     * `xy` must not suggest `id` and `pid`.
     */
    public function testAShortNameOnlyMatchesInAnotherCase(): void
    {
        $this->assertSame([], $this->similar('xy'));
        $this->assertSame(['id'], $this->similar('ID'));
    }

    public function testNothingSimilarSuggestsNothing(): void
    {
        $this->assertSame([], $this->similar('nosuchcolumn'));
    }

    public function testTheMessageNamesTheCandidateAndAllColumns(): void
    {
        $message = $this->message('filter', 'status');

        $this->assertStringStartsWith('filter: unknown column: status.', $message);
        $this->assertStringContainsString('Did you mean: orderStatus?', $message);
        $this->assertStringContainsString('Columns: id, pid, tstamp, orderStatus, providerReference', $message);
    }

    public function testWithoutACandidateTheMessageStillListsTheColumns(): void
    {
        $message = $this->message('order', 'nosuchcolumn');

        $this->assertStringNotContainsString('Did you mean', $message);
        $this->assertStringContainsString('Columns: id, pid', $message);
    }

    /**
     * --filter and --order refuse a sensitive column, so listing it there
     * would offer a column that is turned away on the next attempt.
     */
    public function testASensitiveColumnIsNotOfferedAsAFilter(): void
    {
        $this->assertStringNotContainsString('secret', $this->message('filter', 'nosuchcolumn'));
        $this->assertStringNotContainsString('secret', $this->message('filter-prefix', 'nosuchcolumn'));
        $this->assertStringNotContainsString('secret', $this->message('order', 'nosuchcolumn'));
    }

    /**
     * --fields shows a sensitive column masked, so it is a valid answer there.
     */
    public function testASensitiveColumnIsListedForFields(): void
    {
        $this->assertStringContainsString('secret', $this->message('fields', 'nosuchcolumn'));
    }
}
