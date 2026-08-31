<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * An empty `--set field=` becomes the empty value that column can hold.
 *
 * `--set teaser=` cleared a text column and always worked. `--set addFile=`
 * did not: `tl_newsletter.addFile` is `['type' => 'boolean']`, and MySQL in
 * strict mode answers an empty string with *Incorrect integer value*. The DBAL
 * exception escaped uncaught — a stack trace and exit 255 out of a command
 * whose whole contract is a JSON result. Same syntax, two outcomes, decided by
 * a column type the caller cannot see.
 *
 * 🎯 **The answer is Contao's, not ours.** `Widget::getEmptyValueByFieldType()`
 * takes the DCA `sql` definition and returns the empty value for that column;
 * `DC_Table::save()` calls it for exactly this. Refusing the empty value — the
 * first instinct — would have made this CLI stricter than the back end at a
 * place where Contao has a considered answer and hands it over as a public
 * static method.
 *
 * Reached through ImageSizeUpdateCommand because the method under test lives on
 * AbstractWriteCommand and any concrete command inherits it unchanged.
 */
class EmptyValueConversionTest extends TestCase
{
    private function subject(): ImageSizeUpdateCommand
    {
        return new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convert(array $fields): array
    {
        $method = new \ReflectionMethod($this->subject(), 'convertEmptyValues');

        return $method->invoke($this->subject(), 'tl_test', $fields);
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'teaser'   => ['sql' => "text NULL"],
            'title'    => ['sql' => "varchar(255) NOT NULL default ''"],
            'addFile'  => ['sql' => ['type' => 'boolean', 'default' => false]],
            'sorting'  => ['sql' => "int(10) unsigned NOT NULL default 0"],
            'size'     => ['sql' => ['type' => 'integer', 'notnull' => true]],
            'optional' => ['sql' => ['type' => 'string', 'length' => 255, 'notnull' => false]],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    public function testABooleanColumnGetsFalseRatherThanAnEmptyString(): void
    {
        $this->assertFalse($this->convert(['addFile' => ''])['addFile']);
    }

    public function testAnIntegerColumnGetsZero(): void
    {
        $this->assertSame(0, $this->convert(['sorting' => ''])['sorting']);
        $this->assertSame(0, $this->convert(['size' => ''])['size']);
    }

    /**
     * The reason this needs no special-casing: Contao's mapping answers `''`
     * for a NOT NULL string column, so running every empty value through it
     * leaves text fields exactly as they were.
     */
    public function testANotNullStringColumnIsUntouched(): void
    {
        $this->assertSame('', $this->convert(['title' => ''])['title']);
    }

    public function testANullableColumnGetsNull(): void
    {
        $this->assertNull($this->convert(['teaser' => ''])['teaser']);
        $this->assertNull($this->convert(['optional' => ''])['optional']);
    }

    public function testNonEmptyValuesAreNeverTouched(): void
    {
        $fields = ['addFile' => '1', 'sorting' => '128', 'title' => 'Hello', 'teaser' => 'x'];

        $this->assertSame($fields, $this->convert($fields));
    }

    /**
     * A field with no `sql` entry is left alone rather than guessed at. It is
     * not a column, so there is no column type to answer with.
     */
    public function testAFieldWithoutAnSqlDefinitionIsLeftAlone(): void
    {
        $this->assertSame('', $this->convert(['unknown' => ''])['unknown']);
    }

    /**
     * The conversion has to be reachable through the shared entry point, or
     * only the commands that happen to call it directly are fixed — the same
     * failure that CreateCommandConversionTest exists to prevent.
     */
    public function testTheSharedEntryPointRunsIt(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');

        $this->assertMatchesRegularExpression(
            '/function convertFields\(.*?convertEmptyValues\(/s',
            $source,
            'convertFields() no longer runs convertEmptyValues(), so create and update '
            . 'write empty strings into boolean and integer columns again.',
        );
    }
}
