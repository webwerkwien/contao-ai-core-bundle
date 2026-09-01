<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A create hands over its own options, and the whole record is judged together.
 *
 * 🎯 **Every create command used to take its own options past the rules.**
 * `--set` pairs went through `convertFields()`; `--name`, `--title`, `--email`
 * and the computed values beside them were assigned onto the model afterwards.
 * So `theme create --name "Contao Official Demo"` made a second theme under a
 * name `eval.unique` forbids — half an hour after the unique check was added
 * and believed to cover creates.
 *
 * Three commands had noticed and each written their own check. That is the
 * shape this bundle keeps learning to distrust: a rule every command has to
 * remember, which most of them did not.
 */
class PreparedFieldsTest extends TestCase
{
    /**
     * @param list<string> $columns
     */
    private function subject(array $columns = ['id', 'name', 'alias', 'title']): ImageSizeUpdateCommand
    {
        return new class ($this->createMock(ContaoFramework::class), $columns) extends ImageSizeUpdateCommand {
            /** @param list<string> $columns */
            public function __construct(ContaoFramework $framework, private readonly array $columns)
            {
                parent::__construct($framework);
            }

            protected function tableColumns(string $table): array
            {
                return $this->columns;
            }

            protected function uniqueValueIsFree(string $table, string $field, mixed $value, ?int $excludeId): bool
            {
                return !\in_array((string) $value, ['belegt', 'mein-alias'], true);
            }
        };
    }

    private function prepared(array $own, array $set): array
    {
        $subject = $this->subject();
        $method  = new \ReflectionMethod($subject, 'preparedFields');

        return $method->invoke($subject, 'tl_test', $own, $set);
    }

    private function alias(string $given, string $from, string $table = 'tl_test'): string
    {
        $subject = $this->subject();
        $method  = new \ReflectionMethod($subject, 'resolveAlias');

        return $method->invoke($subject, $table, $given, $from);
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'name'  => ['eval' => ['unique' => true]],
            'alias' => ['eval' => ['unique' => true, 'rgxp' => 'alias']],
            'title' => ['eval' => []],
        ];
        // A table whose alias is NOT unique, like tl_page and tl_article.
        $GLOBALS['TL_DCA']['tl_loose']['fields'] = [
            'alias' => ['eval' => ['rgxp' => 'alias']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test'], $GLOBALS['TL_DCA']['tl_loose']);
    }

    public function testOwnFieldsAndSetFieldsArriveTogether(): void
    {
        $this->assertSame(
            ['name' => 'Autoren', 'title' => 'Titel'],
            $this->prepared(['name' => 'Autoren'], ['title' => 'Titel']),
        );
    }

    /**
     * What the direct assignments did before: they ran first, and the `--set`
     * loop overwrote them. Preserved deliberately — whether `--set name=x`
     * *should* override `--name y` is a separate question.
     */
    public function testSetStillWinsOverTheCommandsOwnOption(): void
    {
        $this->assertSame(['name' => 'aus--set'], $this->prepared(['name' => 'aus--option'], ['name' => 'aus--set']));
    }

    /**
     * The point of the whole change: an option is now judged like any other
     * value. Before, this created a duplicate without a word.
     */
    public function testAnOptionIsSubjectToTheUniqueRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/belegt/');

        $this->prepared(['name' => 'belegt'], []);
    }

    public function testAnOptionIsSubjectToTheRgxpRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->prepared(['alias' => 'nicht erlaubt!'], []);
    }

    public function testAGivenAliasIsKept(): void
    {
        $this->assertSame('wunschalias', $this->alias('wunschalias', 'Irgendein Titel'));
    }

    /**
     * Contao cannot tell `123` apart from a record ID, so it is never a legal
     * alias — the one rule that applies to a given and a generated one alike.
     */
    public function testAPurelyNumericAliasIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->alias('123', 'egal');
    }

    /**
     * Generated and already taken: Contao hands its slug service an
     * `$aliasExists` callback and appends until it fits. Refusing here would
     * make this bundle stricter than the back end for a value nobody typed.
     */
    public function testAGeneratedAliasIsMadeUniqueRatherThanRefused(): void
    {
        $this->assertSame('mein-alias-2', $this->alias('', 'Mein Alias'));
    }

    /**
     * ⚠️ Only where the DCA says unique. `tl_page.alias` and `tl_article.alias`
     * carry none — a page alias may repeat across roots, and Contao scopes that
     * check by root and domain in its own `save_callback`. Suffixing here would
     * rename a page for a clash that is not one.
     */
    public function testAnAliasIsNotSuffixedWhereTheDcaDoesNotDemandUniqueness(): void
    {
        $this->assertSame('mein-alias', $this->alias('', 'Mein Alias', 'tl_loose'));
    }
}
