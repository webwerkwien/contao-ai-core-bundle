<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaSchemaCommand;

/**
 * `dca:schema` answered with array indices instead of option values.
 *
 * `array_keys($def['options'])` was applied unconditionally, and that is right
 * for exactly one of Contao's two forms:
 *
 *   array('de' => 'Deutsch')             assoc — the key IS the value
 *   array('map_default', 'map_always')   list  — the key is 0, 1, …
 *
 * Contao's own DCAs use the list form almost everywhere, so almost every answer
 * was wrong. Confirmed live before fixing: `tl_page.sitemap` came back as
 * `[0, 1, 2]` against a DCA declaring `map_default, map_always, map_never`.
 *
 * 🎯 Two reasons it survived so long, both worth keeping in mind:
 *
 *  - **It reads correctly.** Looking at that line you picture the associative
 *    form, and there it is right.
 *  - **The wrong answer looks like an answer.** `tl_content` declares
 *    `array(1, 2, …, 12)` — keys `0..11` — so the reply is plausible and off by
 *    one throughout. A caller building `--set` from it is rejected by the DCA
 *    and goes looking in the wrong field.
 *
 * That is the same shape as the rest of this project's silent failures, with a
 * turn of the screw: this one does not stay quiet, it *answers*.
 *
 * Reported by the parallel session on the wienerwandern booking module, which
 * hit it on a table of its own and checked `tl_page` to rule out its own DCA.
 */
class DcaSchemaOptionsTest extends TestCase
{
    /**
     * @param array<string, mixed> $definition
     *
     * @return list<string>|null
     */
    private function values(array $definition): ?array
    {
        $command = new DcaSchemaCommand($this->createMock(ContaoFramework::class));
        $method  = new \ReflectionMethod($command, 'optionValues');

        return $method->invoke($command, $definition);
    }

    private function source(array $definition): ?string
    {
        $command = new DcaSchemaCommand($this->createMock(ContaoFramework::class));
        $method  = new \ReflectionMethod($command, 'optionsSource');

        return $method->invoke($command, $definition);
    }

    /**
     * The reported case, with Contao's real values.
     */
    public function testAListGivesItsValuesNotItsIndices(): void
    {
        $out = $this->values(['options' => ['map_default', 'map_always', 'map_never']]);

        $this->assertSame(['map_default', 'map_always', 'map_never'], $out);
    }

    /**
     * The form the old code was written for. It must keep working — the whole
     * point is that both are handled, not that the other one now breaks.
     */
    public function testAnAssociativeArrayStillGivesItsKeys(): void
    {
        $out = $this->values(['options' => ['de' => 'Deutsch', 'en' => 'English']]);

        $this->assertSame(['de', 'en'], $out);
    }

    /**
     * `tl_content` declares array(1, …, 12): keys 0..11, values 1..12. The old
     * answer was plausible and off by one — the worst kind.
     */
    public function testANumericListIsNotShiftedByOne(): void
    {
        $out = $this->values(['options' => [1, 2, 3, 4]]);

        $this->assertSame(['1', '2', '3', '4'], $out);
    }

    /**
     * An optgroup: `array('Group' => array('a', 'b'))`. The group name is a
     * label, not something a caller can set.
     */
    public function testAnOptionGroupIsFlattenedToItsMembers(): void
    {
        $out = $this->values(['options' => [
            'Navigation' => ['navigation', 'customnav'],
            'User'       => ['login', 'logout'],
        ]]);

        $this->assertSame(['navigation', 'customnav', 'login', 'logout'], $out);
    }

    public function testAnOptionGroupWithAssociativeMembersKeepsTheKeys(): void
    {
        $out = $this->values(['options' => ['Group' => ['a' => 'Label A', 'b' => 'Label B']]]);

        $this->assertSame(['a', 'b'], $out);
    }

    public function testAFieldWithoutOptionsAnswersNull(): void
    {
        $this->assertNull($this->values(['inputType' => 'text']));
    }

    public function testANonArrayOptionsEntryIsNotGuessedAt(): void
    {
        $this->assertNull($this->values(['options' => 'something']));
    }

    // --- optionsSource: so a null can be told apart from "no options" ---

    /**
     * A field with a callback or a foreign key *has* options; they are just not
     * in the DCA array. A bare `null` made "takes any value" and "the values
     * exist but not here" look identical.
     *
     * @dataProvider sources
     */
    public function testTheSourceOfTheOptionsIsReported(array $definition, ?string $expected): void
    {
        $this->assertSame($expected, $this->source($definition));
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function sources(): array
    {
        return [
            'static list'   => [['options' => ['a', 'b']], 'static'],
            'callback'      => [['options_callback' => ['tl_x', 'getY']], 'callback'],
            'foreign key'   => [['foreignKey' => 'tl_page.title'], 'foreignKey'],
            'plain text'    => [['inputType' => 'text'], null],
        ];
    }
}
