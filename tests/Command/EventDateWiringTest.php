<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\EventCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UndoRestoreCommand;

/**
 * Where the fixes of the practical test (2026-09-19) are wired in.
 *
 * `EventTimesTest` covers the arithmetic; this covers that create and update go
 * through it, that the answers carry the alias, and the cache note on restore.
 */
class EventDateWiringTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private function update(): EventUpdateCommand
    {
        return new EventUpdateCommand($this->createMock(ContaoFramework::class));
    }

    private function preProcess(array $fields, array $stored): array
    {
        $record = new class($stored) {
            public function __construct(private array $stored) {}
            public function row(): array { return $this->stored; }
        };

        return (new \ReflectionMethod(EventUpdateCommand::class, 'preProcessFields'))->invoke($this->update(), $fields, $record);
    }

    public function testMovingAnAllDayEventMovesItsStoredTimes(): void
    {
        $stored = [
            'startDate' => strtotime('2026-10-04'), 'endDate' => null, 'addTime' => false,
            'startTime' => strtotime('2026-10-04'), 'endTime' => strtotime('2026-10-04 23:59:59'),
        ];

        $fields = $this->preProcess(['startDate' => (string) strtotime('2026-10-11')], $stored);

        $this->assertSame(strtotime('2026-10-11 00:00'), $fields['startTime']);
        $this->assertSame(strtotime('2026-10-11 23:59:59'), $fields['endTime']);
    }

    public function testATimeOnAStoredEventKeepsItsDay(): void
    {
        $stored = ['startDate' => strtotime('2026-10-09'), 'endDate' => null, 'addTime' => false,
                   'startTime' => strtotime('2026-10-09'), 'endTime' => strtotime('2026-10-09 23:59:59')];

        $fields = $this->preProcess(['addTime' => '1', 'startTime' => strtotime('1970-01-01 17:30')], $stored);

        $this->assertSame(strtotime('2026-10-09 17:30'), $fields['startTime']);
    }

    public function testAnEndTimeAloneOnAnAllDayEventIsRefused(): void
    {
        $stored = ['startDate' => strtotime('2026-10-04'), 'addTime' => false,
                   'startTime' => strtotime('2026-10-04'), 'endTime' => strtotime('2026-10-04 23:59:59')];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nothing was written');
        $this->preProcess(['addTime' => '1', 'endTime' => strtotime('1970-01-01 21:00')], $stored);
    }

    public function testEmptyingTheStartDateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start date');
        $this->preProcess(['startDate' => ''], ['startDate' => strtotime('2026-10-04')]);
    }

    public function testFieldsWithoutADateAreLeftAlone(): void
    {
        $this->assertSame(['location' => 'Kahlenberg'], $this->preProcess(['location' => 'Kahlenberg'], ['startDate' => 1]));
    }

    public function testTextIsLeftForTheRefusal(): void
    {
        $this->assertSame(['startTime' => '17:30'], $this->preProcess(['startTime' => '17:30'], ['startDate' => strtotime('2026-10-09')]));
    }

    public function testUpdateOptionsBecomeColumns(): void
    {
        $cmd = $this->update();
        $tester = new CommandTester($cmd);
        $method = new \ReflectionMethod($cmd, 'dateOptionFields');

        $tester->execute(['id' => '1', '--startDate' => 'x'], ['capture_stderr_separately' => true]);
        $this->assertStringContainsString('YYYY-MM-DD', $tester->getDisplay(), 'a bad date is refused before anything runs');

        $input = new \Symfony\Component\Console\Input\ArrayInput(
            ['id' => '1', '--startDate' => '2026-10-11', '--startTime' => '18:00'],
            $cmd->getDefinition(),
        );
        (new \ReflectionProperty(\Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand::class, 'input'))->setValue($cmd, $input);

        $this->assertSame(
            ['startDate' => strtotime('2026-10-11'), 'startTime' => strtotime('1970-01-01 18:00'), 'addTime' => '1'],
            $method->invoke($cmd),
        );
    }

    public function testCreateRefusesAnEndTimeWithoutAStartTime(): void
    {
        $tester = new CommandTester(new EventCreateCommand($this->createMock(ContaoFramework::class)));
        $tester->execute(['--title' => 'Abend', '--pid' => '12', '--endTime' => '20:00']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--endTime needs --startTime', $out['message']);
    }

    public function testCreateRefusesABadDate(): void
    {
        $tester = new CommandTester(new EventCreateCommand($this->createMock(ContaoFramework::class)));
        $tester->execute(['--title' => 'Abend', '--pid' => '12', '--startDate' => '09.10.2026']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('YYYY-MM-DD', $out['message']);
    }

    public function testCreateNamesTheTimesSoTheDcaDefaultCannotFillThem(): void
    {
        // tl_calendar_events.startTime/endTime default to time(); filled in by
        // preparedFields() they made every all-day create a "time without addTime"
        // refusal. Found live on c5 before the v0.28.0 release.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/EventCreateCommand.php');

        $this->assertStringContainsString("\$own       = ['startTime' => null, 'endTime' => null];", $source);
    }

    public function testCreateHasNoEndDateDefault(): void
    {
        $cmd = new EventCreateCommand($this->createMock(ContaoFramework::class));

        $this->assertNull($cmd->getDefinition()->getOption('endDate')->getDefault());
    }

    public function testCreateRunsEventTimesOnTheMergedFields(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/EventCreateCommand.php');

        $this->assertMatchesRegularExpression('/preparedFields\(.*?\);\s*.*?EventTimes::adjust\(\$fields\) \+ \$fields;/s', $source);
    }

    /**
     * @dataProvider createCommands
     */
    public function testCreateAnswersCarryTheAlias(string $file): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $file);
        preg_match('/outputSuccess\((.*?)\);/s', $source, $m);

        $this->assertStringContainsString("'alias'", $m[1] ?? '', $file . ' answers without the alias');
    }

    public static function createCommands(): iterable
    {
        foreach (['ArticleCreateCommand.php', 'NewsCreateCommand.php', 'EventCreateCommand.php', 'FaqCreateCommand.php', 'NewsletterCreateCommand.php'] as $file) {
            yield $file => [$file];
        }
    }

    public function testFaqCreateGeneratesTheAlias(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/FaqCreateCommand.php');

        $this->assertStringContainsString("'alias'     => \$this->resolveAlias('tl_faq', '', (string) \$question", $source);
    }

    public function testRestoringAListSourceWarnsAboutTheCache(): void
    {
        $method = new \ReflectionMethod(UndoRestoreCommand::class, 'listSourcesIn');

        $this->assertSame(
            ['calendar 12', 'FAQ category 3'],
            $method->invoke(null, [
                ['tl_calendar', null, ['id' => 12]],
                ['tl_calendar_events', null, ['id' => 23]],
                ['tl_content', null, ['id' => 5]],
                ['tl_faq_category', null, ['id' => '3']],
                ['tl_calendar', null, ['id' => 12]],
            ]),
        );
        $this->assertSame([], $method->invoke(null, [['tl_calendar_events', null, ['id' => 23]]]), 'a single event restores its tags fine');
    }
}
