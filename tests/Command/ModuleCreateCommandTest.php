<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ModuleCreateCommand;

/**
 * A mandatory field on tl_module is only mandatory for some module types.
 *
 * Twelve of the 113 fields carry `eval.mandatory`, which reads like a command
 * nobody could call. `DC_Table` resolves it by walking the *active palette*: a
 * field outside the palette of the chosen type is never asked for. A navigation
 * needs `pages`, a news list needs `news_archives` and `numberOfItems`, a login
 * module needs neither.
 *
 * The command computes that from the DCA at runtime instead of carrying a
 * table of its own — which also means module types from third-party extensions
 * are covered without anyone adding them here.
 *
 * The palettes are seeded rather than loaded: the point under test is the
 * intersection rule, and a real DCA would drag in a booted container.
 */
class ModuleCreateCommandTest extends TestCase
{
    private function command(): ModuleCreateCommand
    {
        return new ModuleCreateCommand($this->createMock(ContaoFramework::class));
    }

    protected function setUp(): void
    {
        // A cut-down tl_module: two mandatory fields beyond `name`, and three
        // types that ask for none, one and both of them.
        $GLOBALS['TL_DCA']['tl_module'] = [
            'palettes' => [
                '__selector__' => ['type'],
                'login'        => '{title_legend},name,type;{redirect_legend},jumpTo',
                'customnav'    => '{title_legend},name,type;{nav_legend},pages',
                'newslist'     => '{title_legend},name,type;{config_legend},news_archives,numberOfItems',
            ],
            'fields' => [
                'name'          => ['eval' => ['mandatory' => true]],
                'pages'         => ['eval' => ['mandatory' => true, 'multiple' => true]],
                'news_archives' => ['eval' => ['mandatory' => true, 'multiple' => true]],
                'numberOfItems' => ['eval' => ['mandatory' => true]],
                'jumpTo'        => ['eval' => []],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_module']);
        parent::tearDown();
    }

    public function testATypeThatNeedsNothingElseAsksForNothingElse(): void
    {
        $this->assertSame([], $this->command()->missingRequiredFields('login', []));
    }

    public function testATypeNamesTheFieldItNeeds(): void
    {
        $this->assertSame(['pages'], $this->command()->missingRequiredFields('customnav', []));
    }

    public function testATypeNamesEveryFieldItNeeds(): void
    {
        $this->assertSame(
            ['news_archives', 'numberOfItems'],
            $this->command()->missingRequiredFields('newslist', []),
        );
    }

    public function testASuppliedFieldIsNoLongerMissing(): void
    {
        $missing = $this->command()->missingRequiredFields('newslist', ['news_archives' => '1']);

        $this->assertSame(['numberOfItems'], $missing);
    }

    public function testAnEmptyValueDoesNotCountAsSupplied(): void
    {
        $missing = $this->command()->missingRequiredFields('customnav', ['pages' => '']);

        $this->assertSame(['pages'], $missing);
    }

    /**
     * The whole point of computing rather than tabulating: a mandatory field
     * outside this type's palette must not be demanded.
     */
    public function testAMandatoryFieldOutsideThePaletteIsNotDemanded(): void
    {
        $missing = $this->command()->missingRequiredFields('login', []);

        $this->assertNotContains('pages', $missing);
        $this->assertNotContains('news_archives', $missing);
    }

    public function testNameIsNotDemandedHereBecauseItHasItsOwnOption(): void
    {
        $this->assertNotContains('name', $this->command()->missingRequiredFields('customnav', []));
    }

    public function testAnUnknownTypeAsksForNothingRatherThanEverything(): void
    {
        // doExecute() rejects an unknown type before this is reached; the guard
        // is here so a caller of the method alone cannot get a bogus list.
        $this->assertSame([], $this->command()->missingRequiredFields('does_not_exist', []));
    }
}
