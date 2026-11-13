<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Copier;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\MarkAsCopy;

/**
 * `config.markAsCopy` as `DC_Table::copy()` applies it (5.3 to 6.0): the label
 * `MSC.copyOf` around a plain value, around `value` of an inputUnit field, nothing
 * for an empty one, and never a second time (contao/contao#6058).
 */
class MarkAsCopyTest extends TestCase
{
    private const LABEL = '%s (Kopie)';

    public function testPlainValue(): void
    {
        self::assertSame('Team (Kopie)', MarkAsCopy::apply(self::LABEL, 'Team', false));
    }

    public function testInputUnitKeepsTheUnit(): void
    {
        $stored = serialize(['unit' => 'h2', 'value' => 'Über uns']);

        self::assertSame(
            ['unit' => 'h2', 'value' => 'Über uns (Kopie)'],
            unserialize((string) MarkAsCopy::apply(self::LABEL, $stored, true)),
        );
    }

    public function testEmptyValuesStayAsTheyAre(): void
    {
        $emptyUnit = serialize(['unit' => 'h2', 'value' => '']);

        self::assertSame('', MarkAsCopy::apply(self::LABEL, '', false));
        self::assertNull(MarkAsCopy::apply(self::LABEL, null, false));
        self::assertSame($emptyUnit, MarkAsCopy::apply(self::LABEL, $emptyUnit, true));
        self::assertSame('', MarkAsCopy::apply(self::LABEL, '', true));
        // Empty in PHP's sense, as Contao checks it.
        self::assertSame('0', MarkAsCopy::apply(self::LABEL, '0', false));
        self::assertSame(serialize(['unit' => 'h2', 'value' => '0']), MarkAsCopy::apply(self::LABEL, serialize(['unit' => 'h2', 'value' => '0']), true));
    }

    public function testNeverMarkedTwice(): void
    {
        self::assertSame('Team (Kopie)', MarkAsCopy::apply(self::LABEL, 'Team (Kopie)', false));

        $marked = serialize(['unit' => 'h2', 'value' => 'Über uns (Kopie)']);
        self::assertSame($marked, MarkAsCopy::apply(self::LABEL, $marked, true));
    }

    public function testAMarkInEntitiesCountsAsAMark(): void
    {
        // Contao 5.3 and 5.7 decode entities before they look for the mark.
        self::assertSame('A &amp; B (Kopie)', MarkAsCopy::apply(self::LABEL, 'A &amp; B (Kopie)', false));
    }

    public function testTheLabelOfTheLoadedLanguage(): void
    {
        self::assertSame('Team (copy)', MarkAsCopy::apply('%s (copy)', 'Team', false));
    }

    public function testAPlainFieldIsNotReadAsInputUnit(): void
    {
        // Contao goes by the field's inputType, not by what the value looks like.
        $stored = serialize(['value' => 'x']);

        self::assertSame($stored . ' (Kopie)', MarkAsCopy::apply(self::LABEL, $stored, false));
    }
}
