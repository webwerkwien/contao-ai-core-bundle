<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * Text for a number or a point in time is refused by name (v0.28.0).
 *
 * Practical test 2026-09-19: `event create --set startTime=17:30` ended in
 * `DriverException: Data truncated for column 'startTime'`, and a date typed into
 * a varchar timestamp column (`tl_news.start`) would have been stored as text.
 */
class NonNumericValueRefusalTest extends TestCase
{
    private string $tz;

    private function refusalFor(array $fields, string $table = 'tl_test'): ?string
    {
        $subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
        $method  = new \ReflectionMethod($subject, 'refuseNonNumericValues');

        try {
            $method->invoke($subject, $table, $fields);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'title'     => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
            'size'      => ['inputType' => 'text', 'sql' => 'int(10) unsigned NOT NULL default 0'],
            'big'       => ['inputType' => 'text', 'sql' => ['type' => 'bigint', 'notnull' => false]],
            'published' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
            'start'     => ['inputType' => 'text', 'eval' => ['rgxp' => 'datim'], 'sql' => "varchar(10) NOT NULL default ''"],
            'startTime' => ['inputType' => 'text', 'eval' => ['rgxp' => 'time'], 'sql' => 'bigint(20) NULL'],
            'sizes'     => ['inputType' => 'text', 'sql' => 'blob NULL'],
        ];
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields'] = $GLOBALS['TL_DCA']['tl_test']['fields'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test'], $GLOBALS['TL_DCA']['tl_calendar_events']);
        date_default_timezone_set($this->tz);
    }

    public function testNumbersAndEmptyValuesPass(): void
    {
        $this->assertNull($this->refusalFor([
            'size'      => '42',
            'big'       => '-3',
            'start'     => '1790805600',
            'startTime' => '',
            'title'     => 'Kahlenberg 17:30',
            'published' => 'true',   // the boolean refusal's business, not this one's
            'sizes'     => 'a:0:{}',
        ]));
    }

    public function testNonStringValuesAreLeftAlone(): void
    {
        $this->assertNull($this->refusalFor(['size' => 42, 'startTime' => null, 'big' => false]));
    }

    public function testTextInAnIntegerColumnIsRefused(): void
    {
        $message = $this->refusalFor(['size' => 'groß', 'big' => '12abc']);

        $this->assertNotNull($message);
        $this->assertStringContainsString('size=groß', $message);
        $this->assertStringContainsString('big=12abc', $message);
        $this->assertStringContainsString('Nothing was written', $message);
    }

    public function testADateIntoAVarcharTimestampNamesTheTimestamp(): void
    {
        $message = $this->refusalFor(['start' => '2026-10-01']);

        $this->assertNotNull($message);
        $this->assertStringContainsString((string) strtotime('2026-10-01'), $message);
        $this->assertStringContainsString('2026-10-01 00:00 Europe/Vienna', $message);
    }

    public function testAnUnparsableDateStillGetsRefused(): void
    {
        $message = $this->refusalFor(['start' => 'bald']);

        $this->assertNotNull($message);
        $this->assertStringContainsString('start=bald (a Unix timestamp)', $message);
    }

    public function testEventsPointAtTheDateAndTimeOptions(): void
    {
        $message = $this->refusalFor(['startTime' => '17:30'], 'tl_calendar_events');

        $this->assertNotNull($message);
        $this->assertStringContainsString('--startTime/--endTime (HH:MM)', $message);
        $this->assertStringNotContainsString('--startTime', (string) $this->refusalFor(['startTime' => '17:30'], 'tl_test'));
    }

    public function testConvertFieldsRunsTheCheckOnTheStoredForm(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');
        preg_match('/function convertFields\(.*?\n    }\n/s', $source, $m);
        $body = $m[0] ?? '';

        $this->assertNotSame('', $body);
        $this->assertStringContainsString('$this->refuseNonNumericValues($table, $fields);', $body);
        $this->assertGreaterThan(
            strpos($body, 'convertMultipleFields'),
            strpos($body, 'refuseNonNumericValues'),
            'checked after the conversions, on what will be written',
        );
    }
}
