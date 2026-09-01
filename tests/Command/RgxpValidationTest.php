<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * `eval.rgxp` is the rule behind "that is not an e-mail address" in the back end.
 *
 * `DC_Table` enforces it by running every field through its widget. This write
 * path goes around `DC_Table`, so `--set sender=keine` landed in the column
 * unchallenged — 145 `rgxp` declarations across 28 DCA files in a stock Contao
 * 5.7.12, and not one of them was checked.
 *
 * 🎯 **Same shape as `unique` and the empty-value mapping: a rule that lives in
 * the DCA and is lost together with `DC_Table` when you write past it.** And
 * the same answer — every keyword resolves to one `Validator` method, so this
 * runs Contao's rule rather than a second opinion about what a URL is.
 */
class RgxpValidationTest extends TestCase
{
    private function subject(): ImageSizeUpdateCommand
    {
        return new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function check(array $fields): void
    {
        $method = new \ReflectionMethod($this->subject(), 'refuseInvalidValues');
        $method->invoke($this->subject(), 'tl_test', $fields);
    }

    private function refusalFor(array $fields): ?string
    {
        try {
            $this->check($fields);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'plain'   => ['sql' => "varchar(255) NOT NULL default ''"],
            'sender'  => ['eval' => ['rgxp' => 'email']],
            'cc'      => ['eval' => ['rgxp' => 'emails']],
            'from'    => ['eval' => ['rgxp' => 'friendly']],
            'website' => ['eval' => ['rgxp' => 'url']],
            'alias'   => ['eval' => ['rgxp' => 'alias']],
            'amount'  => ['eval' => ['rgxp' => 'natural']],
            'stamp'   => ['eval' => ['rgxp' => 'datim']],
            'custom'  => ['eval' => ['rgxp' => 'somethingAnExtensionAdded']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    public function testAValidValuePasses(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['sender' => 'info@example.com', 'amount' => '5', 'alias' => 'mein-alias']);
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $message = $this->refusalFor(['sender' => 'keinemailadresse']);

        $this->assertNotNull($message);
        $this->assertStringContainsString('sender', $message);
        $this->assertStringContainsString('email', $message);
    }

    public function testANegativeNumberFailsNatural(): void
    {
        $this->assertNotNull($this->refusalFor(['amount' => '-3']));
    }

    public function testEveryOffenderIsNamedAtOnce(): void
    {
        $message = (string) $this->refusalFor(['sender' => 'nope', 'alias' => 'nicht erlaubt!', 'amount' => '7']);

        $this->assertStringContainsString('sender', $message);
        $this->assertStringContainsString('alias', $message);
        $this->assertStringNotContainsString('amount', $message);
    }

    /**
     * `Widget::validator()` returns before the rgxp switch when the input is
     * `''`, so an empty optional field is not a format error. This also has to
     * compose with `convertEmptyValues()`, which maps the empty value
     * afterwards.
     */
    public function testAnEmptyValueIsNotAFormatError(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['sender' => '', 'website' => '', 'amount' => '']);
    }

    /**
     * ⚠️ The decision this class exists to pin down.
     *
     * `date`, `time` and `datim` describe the *widget input* — a date in the
     * configured display format — and `DC_Table::save()` converts that to a
     * timestamp before it reaches the column. `--set` writes the stored form,
     * so a timestamp is the correct value here and `Validator::isDatim()`
     * would refuse it. Checking these would reject the right value.
     */
    public function testTimestampsAreNotJudgedAgainstTheDisplayFormat(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['stamp' => '1756598400']);
    }

    /**
     * Extensions add their own keywords through the `addCustomRegexp` hook.
     * Refusing what this list does not know would break a field whose rule
     * simply lives elsewhere.
     */
    public function testAnUnknownRgxpIsSkippedRatherThanRefused(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['custom' => 'whatever the extension allows']);
    }

    public function testAFieldWithoutAnRgxpIsUntouched(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['plain' => 'anything at all !!!']);
    }

    public function testAnInsertTagSurvivesTheUrlRule(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['website' => '{{link_url::5}}']);
    }

    public function testAnEmailListIsCheckedEntryByEntry(): void
    {
        $this->assertNull($this->refusalFor(['cc' => 'a@example.com,b@example.com']));
        $this->assertNotNull($this->refusalFor(['cc' => 'a@example.com,kaputt']));
    }

    public function testAFriendlyAddressIsSplitBeforeChecking(): void
    {
        $this->assertNull($this->refusalFor(['from' => 'Michael <info@example.com>']));
        $this->assertNotNull($this->refusalFor(['from' => 'Michael <kaputt>']));
    }

    /**
     * The check has to sit in the shared entry point, and it has to run on the
     * raw input — after the conversions a UUID is binary and a list is a
     * serialized string, and neither would survive its own rule.
     */
    public function testTheSharedEntryPointRunsItBeforeTheConversions(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');

        $this->assertMatchesRegularExpression(
            '/function convertFields\(.*?refuseInvalidValues\(.*?convertFileTreeFields\(/s',
            $source,
            'convertFields() must run refuseInvalidValues() before the conversions, or the '
            . 'rules judge a value the caller never typed.',
        );
    }
}
