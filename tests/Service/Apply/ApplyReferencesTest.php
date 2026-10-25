<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Apply;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\ApplyReferences;

/**
 * `@name` stands for the ID of an earlier step in two places only: as a whole value
 * and as the argument of an insert tag (v1.13.0). Everywhere else an `@` is text --
 * an e-mail address or a mention must come through unchanged.
 */
class ApplyReferencesTest extends TestCase
{
    private const IDS = ['versand' => 812, 'versand.article' => 1290, 'intro' => 4410];

    public function testAWholeValueBecomesTheId(): void
    {
        $this->assertSame(812, ApplyReferences::resolve('@versand', self::IDS));
        $this->assertSame(1290, ApplyReferences::resolve('@versand.article', self::IDS));
    }

    public function testAnInsertTagArgumentIsReplaced(): void
    {
        $this->assertSame(
            '<a href="{{link_url::812}}">x</a> {{insert_content::4410}} {{link_url::812|absolute}}',
            ApplyReferences::resolve('<a href="{{link_url::@versand}}">x</a> {{insert_content::@intro}} {{link_url::@versand|absolute}}', self::IDS),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouched(): iterable
    {
        yield 'e-mail' => ['Write to versand@example.com'];
        yield 'mention' => ['Follow @versand on social media'];
        yield 'plain href' => ['<a href="@versand">x</a>'];
        yield 'number' => ['1290'];
        yield 'no at' => ['{{link_url::12}}'];
    }

    /**
     * @dataProvider untouched
     */
    public function testTextElsewhereIsLeftAlone(string $text): void
    {
        $this->assertSame($text, ApplyReferences::resolve($text, self::IDS));
        $this->assertSame([], ApplyReferences::find(['do' => 'x', 'set' => ['text' => $text]]));
    }

    public function testNestedValuesAreResolved(): void
    {
        $this->assertSame(
            ['unit' => 'h2', 'value' => 'See {{link_url::812}}'],
            ApplyReferences::resolve(['unit' => 'h2', 'value' => 'See {{link_url::@versand}}'], self::IDS),
        );
    }

    public function testFindListsBothPositionsButNotDoOrRef(): void
    {
        $step = ['do' => '@do', 'ref' => '@ref', 'pid' => '@versand.article', 'set' => ['text' => '{{link_url::@intro}} {{link_open::@versand}}']];

        $this->assertSame(['versand.article', 'intro', 'versand'], ApplyReferences::find($step));
    }

    public function testAnUnknownNameIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApplyReferences::resolve('{{link_url::@nowhere}}', self::IDS);
    }
}
