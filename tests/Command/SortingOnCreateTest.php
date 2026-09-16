<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A record created in a sorted table goes behind its last sibling.
 *
 * 🔴 **Measured on 2026-09-16 on c5 (Contao 5.7.13, core-bundle v0.13.0)** in the ConpAI
 * 1.0 acceptance test: every page, article and content element created through the
 * CLI had `sorting = 0`. Four content elements in one article, all 0 — the order on
 * the page held only by how the database breaks a tie. Pages and roots the same.
 *
 * Two create commands did it right, each with a private `nextSorting()` of its own:
 * `FormFieldCreateCommand` and `ImageSizeItemCreateCommand`. The other four forgot —
 * the shape this bundle keeps meeting, a rule every command has to remember.
 *
 * ## The rule
 *
 * Contao's step is 128 (`DC_Table::getNewPosition()`, 5.7.13). A command without a
 * position means "at the end", so the new value is `MAX(sorting)` of the siblings plus
 * 128 — siblings being the same `pid`, and for a `dynamicPtable` table (tl_content)
 * the same `ptable`. A `--set sorting=` given by the caller still wins
 * (`preparedFields()` lets `--set` override).
 *
 * The tables, counted on c5 (a `pid` and a `sorting` column, and a create command):
 * tl_page, tl_article, tl_content, tl_faq, tl_form_field, tl_image_size_item.
 */
class SortingOnCreateTest extends TestCase
{
    /** command file => whether it has to pass the ptable */
    private const SORTED = [
        'PageCreateCommand.php'          => false,
        'ArticleCreateCommand.php'       => false,
        'ContentCreateCommand.php'       => true,
        'FaqCreateCommand.php'           => false,
        'FormFieldCreateCommand.php'     => false,
        'ImageSizeItemCreateCommand.php' => false,
    ];

    /**
     * @dataProvider maxima
     */
    public function testTheNewValueComesAfterTheLastSibling(?int $max, int $expected): void
    {
        $subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        $this->assertSame($expected, (new \ReflectionMethod($subject, 'sortingAfter'))->invoke($subject, $max));
    }

    /**
     * @return array<string, array{?int, int}>
     */
    public static function maxima(): array
    {
        return [
            'no sibling'                => [null, 128],
            'only siblings with 0'      => [0, 128],
            'after a regular sequence'  => [384, 512],
            'after an uneven value'     => [100, 228],
        ];
    }

    /**
     * @dataProvider sortedCommands
     */
    public function testEveryCreateCommandOfASortedTableSetsIt(string $file, bool $withPtable): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $file);

        $this->assertMatchesRegularExpression(
            "/'sorting'\s*=>\s*\\\$this->nextSorting\(/",
            $source,
            "{$file} must hand 'sorting' => \$this->nextSorting(...) to preparedFields(), so --set can still override it",
        );

        $this->assertStringNotContainsString(
            'private function nextSorting',
            $source,
            "{$file} keeps its own copy — use the shared one in AbstractWriteCommand",
        );

        if ($withPtable) {
            $this->assertMatchesRegularExpression('/nextSorting\([^;]*ptable/i', $source, "{$file} must count siblings of the same ptable");
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function sortedCommands(): array
    {
        $cases = [];
        foreach (self::SORTED as $file => $withPtable) {
            $cases[$file] = [$file, $withPtable];
        }

        return $cases;
    }
}
