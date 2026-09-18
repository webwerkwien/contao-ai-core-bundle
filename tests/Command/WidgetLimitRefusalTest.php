<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * `Widget::validator()` refuses more than `rgxp`: `maxlength`, `minlength`,
 * `minval`, `maxval` and `nospace`. None of it was checked on this write path.
 *
 * Found in the review before v0.26.0: `member create --username "anna muster"`
 * was stored where the back end says "no spaces allowed", and a username over
 * 64 characters ended in a database error (strict) or was cut off (lax).
 */
class WidgetLimitRefusalTest extends TestCase
{
    private function refusalFor(array $fields): ?string
    {
        $subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
        $method  = new \ReflectionMethod($subject, 'refuseWidgetLimitViolations');

        try {
            $method->invoke($subject, 'tl_test', $fields);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'plain'    => ['inputType' => 'text'],
            'username' => ['inputType' => 'text', 'eval' => ['nospace' => true, 'maxlength' => 64]],
            'city'     => ['inputType' => 'text', 'eval' => ['maxlength' => 5]],
            'code'     => ['inputType' => 'text', 'eval' => ['minlength' => 3]],
            'amount'   => ['inputType' => 'text', 'eval' => ['minval' => 2, 'maxval' => 10]],
            'zero'     => ['inputType' => 'text', 'eval' => ['minval' => 0]],
            'password' => ['inputType' => 'password', 'eval' => ['minlength' => 8, 'maxlength' => 10]],
            'headline' => ['inputType' => 'inputUnit', 'options' => ['h1', 'h2'], 'eval' => ['maxlength' => 5]],
            'items'    => ['inputType' => 'listWizard', 'eval' => ['maxlength' => 5]],
            'tags'     => ['inputType' => 'text', 'eval' => ['multiple' => true, 'size' => 3, 'maxlength' => 4]],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    public function testValuesWithinTheLimitsPass(): void
    {
        $this->assertNull($this->refusalFor([
            'username' => 'anna.muster',
            'city'     => 'Wien',
            'code'     => 'abc',
            'amount'   => '5',
            'plain'    => str_repeat('x', 1000),
        ]));
    }

    public function testASpaceInANospaceFieldIsRefused(): void
    {
        $message = $this->refusalFor(['username' => 'anna muster']);

        $this->assertNotNull($message);
        $this->assertStringContainsString('username="anna muster" (no spaces allowed)', $message);
        $this->assertStringContainsString('Nothing was written', $message);
    }

    public function testATabCountsAsASpace(): void
    {
        $this->assertNotNull($this->refusalFor(['username' => "anna\tmuster"]));
    }

    public function testSurroundingSpaceIsTrimmedFirstLikeTheWidget(): void
    {
        $this->assertNull($this->refusalFor(['username' => ' anna ']));
    }

    public function testMaxlengthCountsCharactersNotBytes(): void
    {
        $this->assertNull($this->refusalFor(['city' => 'Wöörl']));
        $this->assertStringContainsString(
            'city has 6 characters (maxlength 5)',
            (string) $this->refusalFor(['city' => 'Wöörls']),
        );
    }

    public function testMinlength(): void
    {
        $this->assertStringContainsString('(minlength 3)', (string) $this->refusalFor(['code' => 'ab']));
    }

    public function testAnEmptyValueIsNotALengthError(): void
    {
        $this->assertNull($this->refusalFor(['code' => '', 'username' => '   ']));
    }

    public function testMinvalAndMaxvalOnlyForNumbers(): void
    {
        $this->assertStringContainsString('amount=1 (minval 2)', (string) $this->refusalFor(['amount' => '1']));
        $this->assertStringContainsString('amount=11 (maxval 10)', (string) $this->refusalFor(['amount' => '11']));
        $this->assertNull($this->refusalFor(['amount' => 'x']));
    }

    public function testAMinvalOfZeroIsNoRuleAsInContao(): void
    {
        $this->assertNull($this->refusalFor(['zero' => '-5']));
    }

    public function testAPasswordFieldIsLeftToThePasswordCheck(): void
    {
        $this->assertNull($this->refusalFor(['password' => '$2y$13$' . str_repeat('a', 53)]));
    }

    public function testOnlyTheValueHalfOfAnInputUnitIsMeasured(): void
    {
        $this->assertNull($this->refusalFor(['headline' => serialize(['value' => 'Hallo', 'unit' => 'h2'])]));
        $this->assertNotNull($this->refusalFor(['headline' => serialize(['value' => 'Hallo!', 'unit' => 'h2'])]));
    }

    public function testASerializedListIsMeasuredEntryByEntry(): void
    {
        $this->assertNull($this->refusalFor(['items' => serialize(['Eins', 'Zwei', 'Drei'])]));
        $this->assertStringContainsString(
            'items has 6 characters',
            (string) $this->refusalFor(['items' => serialize(['Eins', 'Sieben'])]),
        );
    }

    public function testMultiValueTextEntriesAreMeasuredOneByOne(): void
    {
        $this->assertNull($this->refusalFor(['tags' => 'abcd,efgh']));
        $this->assertNotNull($this->refusalFor(['tags' => 'abcd,efghi']));
    }

    public function testEveryOffenderIsNamedAtOnce(): void
    {
        $message = (string) $this->refusalFor(['username' => 'a b', 'city' => 'Klosterneuburg']);

        $this->assertStringContainsString('username=', $message);
        $this->assertStringContainsString('city has', $message);
    }

    public function testZeroIsNeverALengthErrorAsInTheWidget(): void
    {
        $this->assertNull($this->refusalFor(['code' => '0']));
    }

    public function testTheColumnLengthIsTheLimitWhenEvalHasNone(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['short'] = ['inputType' => 'text', 'sql' => ['type' => 'string', 'length' => 4]];
        $GLOBALS['TL_DCA']['tl_test']['fields']['uuid']  = ['inputType' => 'fileTree', 'sql' => ['type' => 'binary', 'length' => 16]];
        $GLOBALS['TL_DCA']['tl_test']['fields']['own']   = ['inputType' => 'text', 'sql' => ['type' => 'string', 'length' => 4, 'columnDefinition' => 'x']];

        $this->assertStringContainsString('(maxlength 4)', (string) $this->refusalFor(['short' => 'fünfe']));
        $this->assertNull($this->refusalFor(['uuid' => str_repeat('a', 36), 'own' => 'fünfe']));
    }

    public function testTheCommaFormOfAListIsMeasuredEntryByEntry(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['boxes'] = ['inputType' => 'checkbox', 'eval' => ['multiple' => true, 'maxlength' => 4]];

        $this->assertNull($this->refusalFor(['boxes' => 'abcd,efgh']));
        $this->assertNotNull($this->refusalFor(['boxes' => 'abcd,efghi']));
    }

    public function testFieldsWithoutAnyLimitAreSkipped(): void
    {
        $this->assertNull($this->refusalFor(['unknown' => 'a b', 'plain' => 'a b']));
    }
}
