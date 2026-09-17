<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A field with a declared option list takes one of those values — nothing else.
 *
 * 🔴 **The gap, measured on 2026-09-11.** `--set consho_shop=999` wrote a number
 * with no record behind it and answered `{"status":"ok"}`. The back end prevents
 * that with a select list; the database does not, and this write path goes
 * around the widget. Same shape as `unique`, `eval.rgxp` and the empty-value
 * mapping: a rule that lives in the DCA and is lost together with `DC_Table`.
 *
 * ## The scope is the point of this file
 *
 * Of 1183 fields in a stock 5.7.13 with all five optional bundles, 279 declare
 * an options source — 94 `options`, 79 `foreignKey`, 106 `options_callback`.
 * **Only the 94 are enforced**, and the two exclusions are not oversights:
 *
 * - **`foreignKey`** — of 55 scalar, checkable foreign-key fields, one was
 *   broken: `tl_news.jumpTo` points at page 13 in all 27 rows that set it, and
 *   page 13 does not exist. The field is declared `mandatory`. Contao allowed
 *   the page to be deleted and cleans up nothing, so a dangling reference is a
 *   state Contao itself produces. Refusing it here would make this CLI stricter
 *   than the framework.
 * - **`options_callback`** — needs a live `DataContainer` this path does not
 *   have, and may answer differently per record.
 *
 * Both have a test below, because an exclusion nobody can see is
 * indistinguishable from a rule that forgot a case.
 *
 * Reached through ImageSizeUpdateCommand because the method under test lives on
 * AbstractWriteCommand and any concrete command inherits it unchanged — the
 * same route EmptyValueConversionTest and BooleanValueRefusalTest take.
 */
class OptionValueRefusalTest extends TestCase
{
    private function refuse(array $fields): void
    {
        $subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
        $method  = new \ReflectionMethod($subject, 'refuseInvalidOptions');
        $method->invoke($subject, 'tl_test', $fields);
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            // Contao's usual shape: a list, where the value is the value.
            'sitemap'   => ['options' => ['map_default', 'map_always', 'map_never']],
            // The other form: associative, where the key is the value.
            'language'  => ['options' => ['de' => 'Deutsch', 'en' => 'English']],
            // An optgroup — the group name is a label, not a value.
            'grouped'   => ['options' => ['Group A' => ['a1', 'a2'], 'Group B' => ['b1']]],
            // Numeric list: keys 0..11, values 1..12. The off-by-one trap.
            'span'      => ['options' => [1, 2, 3]],
            // A list declared associative: the index is the value. Contao's
            // `tl_page.useSSL`, the only such field in 5.7.13 (Nr. 53).
            'useSSL'    => ['options' => ['http://', 'https://'], 'eval' => ['isAssociative' => true]],
            // Multi-value: arrives as "a,b" and is serialized further down.
            'tags'      => ['options' => ['red', 'green', 'blue'], 'eval' => ['multiple' => true]],
            // Values live elsewhere — deliberately not enforced.
            'jumpTo'    => ['foreignKey' => 'tl_page.title', 'inputType' => 'pageTree'],
            'customTpl' => ['options_callback' => ['tl_test', 'getTemplates']],
            // No option list at all.
            'title'     => ['inputType' => 'text'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    // --- what passes ---

    /**
     * @dataProvider accepted
     */
    public function testADeclaredValuePasses(array $fields): void
    {
        $this->refuse($fields);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function accepted(): array
    {
        return [
            'list form'             => [['sitemap' => 'map_always']],
            'associative form'      => [['language' => 'de']],
            'member of an optgroup' => [['grouped' => 'b1']],
            'numeric list value'    => [['span' => '2']],
            'isAssociative index'   => [['useSSL' => '1']],
            'isAssociative zero'    => [['useSSL' => '0']],
            'several multi values'  => [['tags' => 'red,blue']],
            'multi with spaces'     => [['tags' => 'red, blue']],
            'already serialized'    => [['tags' => 'a:2:{i:0;s:3:"red";i:1;s:4:"blue";}']],
            'empty clears'          => [['sitemap' => '']],
            'field without options' => [['title' => 'anything at all']],
            'unknown field'         => [['gibtesnicht' => 'x']],
        ];
    }

    // --- what is refused ---

    /**
     * @dataProvider refused
     */
    public function testAnythingElseIsRefused(array $fields, string $expectInMessage): void
    {
        try {
            $this->refuse($fields);
            $this->fail('Expected an InvalidArgumentException for ' . key($fields));
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Not an allowed value', $e->getMessage());
            $this->assertStringContainsString($expectInMessage, $e->getMessage());
            $this->assertStringContainsString('Nothing was written', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refused(): array
    {
        return [
            'a typo'                    => [['sitemap' => 'map_alwys'], 'sitemap=map_alwys'],
            'a label instead of a key'  => [['language' => 'Deutsch'], 'language=Deutsch'],
            'the optgroup name itself'  => [['grouped' => 'Group A'], 'grouped=Group A'],
            'the index, not the value'  => [['span' => '0'], 'span=0'],
            'isAssociative label'       => [['useSSL' => 'https://'], 'useSSL=https://'],
            'one bad part of several'   => [['tags' => 'red,purple'], 'tags=purple'],
            'bad part when serialized'  => [['tags' => 'a:1:{i:0;s:6:"purple";}'], 'tags=purple'],
        ];
    }

    /**
     * The message has to say what *is* allowed — a refusal a caller cannot act
     * on costs a round trip for nothing.
     */
    public function testTheMessageNamesTheAllowedValues(): void
    {
        try {
            $this->refuse(['sitemap' => 'map_alwys']);
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('map_default, map_always, map_never', $e->getMessage());
        }
    }

    // --- the two exclusions, stated as tests ---

    /**
     * 🎯 **Not an oversight.** `tl_news.jumpTo` points at a page that does not
     * exist in all 27 rows that set it, on a stock installation, in a field
     * declared `mandatory`. Contao allowed the page to be deleted and cleans up
     * nothing. Enforcing this on write would make the CLI stricter than the
     * framework it speaks for.
     */
    public function testAForeignKeyIsNotEnforced(): void
    {
        $this->refuse(['jumpTo' => '999999']);
        $this->addToAssertionCount(1);
    }

    /**
     * An `options_callback` may need a live DataContainer and may answer
     * differently per record. Same posture as an unknown `rgxp` keyword: what
     * an extension contributes is not ours to reject.
     */
    public function testAnOptionsCallbackIsNotEnforced(): void
    {
        $this->refuse(['customTpl' => 'ce_whatever']);
        $this->addToAssertionCount(1);
    }

    /**
     * ⚠️ **The test that keeps the two above honest.** They pass whether the
     * rule excludes those sources on purpose or does nothing at all. This one
     * fails in the second case.
     */
    public function testTheRuleFiresAtAll(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->refuse(['sitemap' => 'definitely_not_an_option']);
    }
}
