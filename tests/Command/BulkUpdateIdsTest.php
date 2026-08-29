<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * `--ids` — the deterministic bulk path added on 2026-08-29.
 *
 * Setting one field on 174 pages took about four minutes through the CLI: 1.4 s
 * per record, of which 0.67 s was nothing but establishing the SSH connection.
 * The alternative on offer was `bridge rewrite`, which is an LLM loop and costs
 * API tokens to write a constant. This closes the gap between "exactly one ID"
 * and "hand it to a language model".
 *
 * The audit trail is the reason the detour through the console exists in the
 * first place, so bulk keeps one version per record. Only the connection is
 * shared, never the history.
 */
class BulkUpdateIdsTest extends TestCase
{
    private function command(): PageUpdateCommand
    {
        $cmd = new PageUpdateCommand($this->createMock(ContaoFramework::class));
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }

    public function testParsesACommaSeparatedList(): void
    {
        $this->assertSame([39, 40, 41], $this->command()->parseIdList('39,40,41'));
    }

    public function testToleratesWhitespaceAndTrailingSeparators(): void
    {
        $this->assertSame([39, 40, 41], $this->command()->parseIdList(' 39, 40 ,41 , '));
    }

    public function testDropsDuplicatesButKeepsTheGivenOrder(): void
    {
        $this->assertSame([41, 39, 40], $this->command()->parseIdList('41,39,41,40,39'));
    }

    /**
     * @dataProvider rubbish
     */
    public function testRefusesAnythingThatIsNotAPositiveInteger(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->command()->parseIdList($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rubbish(): iterable
    {
        yield 'letters'         => ['39,foo,41'];
        yield 'negative'        => ['39,-2'];
        yield 'zero'            => ['0'];
        yield 'empty'           => [''];
        yield 'separators only' => [', ,'];
        yield 'a range'         => ['39-41'];
    }

    public function testRefusesBothAnIdAndAnIdList(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['id' => '39', '--ids' => '40,41', '--set' => ['max_teiln=4']]);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--ids', $out['message']);
    }

    public function testRefusesNeitherAnIdNorAnIdList(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--set' => ['max_teiln=4']]);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--ids', $out['message']);
    }

    public function testRefusesAnIdListWithoutFields(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--ids' => '39,40']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('field', $out['message']);
    }

    public function testReportsAMalformedIdListInsteadOfSilentlySkipping(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--ids' => '39,foo', '--set' => ['max_teiln=4']]);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('foo', $out['message'], 'Name the offending value.');
    }

    /**
     * The single-ID path predates this and is what every existing caller uses.
     * Its output shape must not change.
     */
    public function testTheSingleIdArgumentStillTakesPrecedenceOverNothing(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['id' => '39']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('field', $out['message']);
    }
}
