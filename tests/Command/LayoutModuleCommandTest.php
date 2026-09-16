<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\LayoutModuleCommand;

/**
 * `contao:layout:module --layout 25 --module 66 --col header [--remove]`
 *
 * 🟡 **Found on 2026-09-16** in the ConpAI 1.0 acceptance test: putting a module into a
 * layout meant composing the `moduleWizard` value by hand — a PHP-serialized list,
 * built with `php -r 'echo serialize(…)'`. A layout without modules renders nothing, so
 * the step that makes a page visible needed the format this bundle warns against.
 * Since v0.16.0 the value can be written as JSON; this command adds what JSON cannot:
 * the module has to exist and belong to the layout's theme, and the column has to exist.
 *
 * The columns of a classic `fe_page` layout are derived exactly as Contao's
 * `ModuleWizard` does (5.7.13): `main` always, `header` for rows `2rwh`/`3rw`, `left` for
 * cols `2cll`/`3cl`, `right` for `2clr`/`3cl`, `footer` for `2rwf`/`3rw`, plus the ids of
 * custom sections. A Twig layout takes its slots from the template; those are not
 * checked, and the answer says so.
 */
class LayoutModuleCommandTest extends TestCase
{
    /**
     * @dataProvider layouts
     *
     * @param array<string, mixed> $row
     * @param list<string>          $expected
     */
    public function testTheColumnsAreThoseOfContaosModuleWizard(array $row, array $expected): void
    {
        $this->assertSame($expected, LayoutModuleCommand::legacyColumns($row));
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function layouts(): array
    {
        return [
            'layout 25 on c5 (2rwh, 2cll)' => [['rows' => '2rwh', 'cols' => '2cll', 'sections' => null], ['main', 'header', 'left']],
            'one column, no rows'          => [['rows' => '1rw', 'cols' => '1cl', 'sections' => ''], ['main']],
            'everything'                   => [['rows' => '3rw', 'cols' => '3cl', 'sections' => ''], ['main', 'header', 'left', 'right', 'footer']],
            'custom section'               => [['rows' => '1rw', 'cols' => '1cl', 'sections' => serialize([['title' => 'Hero', 'id' => 'hero', 'position' => 'top']])], ['main', 'hero']],
        ];
    }

    public function testAddingAppendsOnce(): void
    {
        $modules = [['mod' => '0', 'col' => 'main', 'enable' => '1']];

        [$after, $changed] = LayoutModuleCommand::applyChange($modules, 66, 'header', false);
        $this->assertTrue($changed);
        $this->assertSame([['mod' => '0', 'col' => 'main', 'enable' => '1'], ['mod' => '66', 'col' => 'header', 'enable' => '1']], $after);

        [$again, $changedAgain] = LayoutModuleCommand::applyChange($after, 66, 'header', false);
        $this->assertFalse($changedAgain, 'adding what is already there changes nothing');
        $this->assertSame($after, $again);
    }

    public function testRemovingTakesTheModuleOutOfTheGivenColumnOrEverywhere(): void
    {
        $modules = [
            ['mod' => '66', 'col' => 'header', 'enable' => '1'],
            ['mod' => '66', 'col' => 'left', 'enable' => '1'],
            ['mod' => '0', 'col' => 'main', 'enable' => '1'],
        ];

        [$oneColumn] = LayoutModuleCommand::applyChange($modules, 66, 'left', true);
        $this->assertSame([['mod' => '66', 'col' => 'header', 'enable' => '1'], ['mod' => '0', 'col' => 'main', 'enable' => '1']], $oneColumn);

        [$everywhere, $changed] = LayoutModuleCommand::applyChange($modules, 66, null, true);
        $this->assertTrue($changed);
        $this->assertSame([['mod' => '0', 'col' => 'main', 'enable' => '1']], $everywhere);

        [, $nothing] = LayoutModuleCommand::applyChange($modules, 99, null, true);
        $this->assertFalse($nothing);
    }
}
