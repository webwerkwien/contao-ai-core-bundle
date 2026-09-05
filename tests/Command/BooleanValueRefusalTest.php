<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A boolean column takes 1, 0 or nothing — anything else is refused.
 *
 * 🔴 **Why this exists.** Measured on 2026-09-05 against 5.7.13 and 6.0.0 with
 * `contao:page:update --set`:
 *
 * | input | Contao 5.7.13 | Contao 6.0.0 |
 * |---|---|---|
 * | `published=vielleicht` | exit 1, `DriverException` | **exit 0, `published=true`** |
 * | `published=true` | exit 1, `DriverException` | **exit 0, `published=true`** |
 * | `published=1` / `=0` / `=` | correct | correct |
 *
 * Contao 6 casts into the column's declared type instead of letting the
 * database refuse the value. `(bool) 'vielleicht'` is `true`, so a typo
 * publishes the page and the command reports success. On Contao 5 the same
 * input was an error.
 *
 * ⚠️ **Not a defect in Contao.** In the back end the value comes from a
 * checkbox widget that cannot produce `vielleicht`. This write path goes around
 * `DC_Table`, so the widget's constraint is lost — the same shape as `unique`,
 * `eval.rgxp` and the empty-value mapping, with the same answer.
 *
 * Reached through ImageSizeUpdateCommand because the method under test lives on
 * AbstractWriteCommand and any concrete command inherits it unchanged — the
 * same route EmptyValueConversionTest takes.
 */
class BooleanValueRefusalTest extends TestCase
{
    private function refuse(array $fields): void
    {
        $subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
        $method  = new \ReflectionMethod($subject, 'refuseInvalidBooleans');
        $method->invoke($subject, 'tl_test', $fields);
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            // The shape tl_page.published really has, in 5.7 and 6.0 alike.
            'published' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
            'protected' => ['sql' => ['type' => 'boolean', 'default' => false]],
            'title'     => ['sql' => "varchar(255) NOT NULL default ''"],
            'sorting'   => ['sql' => ['type' => 'integer', 'notnull' => true]],
            // A `sql` given as a string declares a text column; nothing to cast.
            'legacy'    => ['sql' => "char(1) NOT NULL default ''"],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function acceptedProvider(): array
    {
        return [
            "'1'"           => ['1'],
            "'0'"           => ['0'],
            'empty string'  => [''],
            'true'          => [true],
            'false'         => [false],
            'int 1'         => [1],
            'int 0'         => [0],
        ];
    }

    /**
     * @dataProvider acceptedProvider
     */
    public function testTheValuesContaoItselfProducesArePassedThrough(mixed $value): void
    {
        $this->refuse(['published' => $value]);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'a typo'            => ['vielleicht'],
            'the word true'     => ['true'],
            'the word false'    => ['false'],
            'the word yes'      => ['yes'],
            'a number above 1'  => ['2'],
            'whitespace'        => [' '],
        ];
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testAnythingElseIsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Not a boolean value/');
        $this->refuse(['published' => $value]);
    }

    /**
     * 🎯 The one that keeps this honest: `false` is the more dangerous
     * direction. A refusal that only caught "turns it on" would still let
     * `published=nein` unpublish nothing and report success.
     */
    public function testTheMessageNamesTheFieldAndTheValue(): void
    {
        try {
            $this->refuse(['published' => 'nein', 'protected' => 'ja']);
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('published=nein', $e->getMessage());
            $this->assertStringContainsString('protected=ja', $e->getMessage());
            $this->assertStringContainsString('Nothing was written', $e->getMessage());
        }
    }

    public function testNonBooleanColumnsAreNotTouched(): void
    {
        $this->refuse(['title' => 'vielleicht', 'sorting' => '7', 'legacy' => 'x']);
        $this->addToAssertionCount(1);
    }

    /**
     * A field that is not in the DCA at all is `refuseUnknownFields()`'s job,
     * not this one. Two rules refusing the same input would produce whichever
     * message ran first.
     */
    public function testAnUnknownFieldIsLeftToTheOtherRule(): void
    {
        $this->refuse(['gibtesnicht' => 'vielleicht']);
        $this->addToAssertionCount(1);
    }
}
