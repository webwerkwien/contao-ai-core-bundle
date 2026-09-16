<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Dca;

use Contao\DataContainer;
use Contao\System;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\ContaoAlias;

/**
 * A generated alias is the one Contao's own `save_callback` makes.
 *
 * 🔴 **Measured on 2026-09-16** (review of v0.16.0, Contao 6.0.0 test installation):
 * `page create --title="Über uns"` stored `über-uns`, a clone of the same page
 * `uber-uns-kopie`. The create commands used `StringUtil::generateAlias()`; Contao's
 * callbacks (`tl_article::generateAlias` and its twins in news, calendar and
 * newsletter) use the `contao.slug` service with the options of the page the record
 * belongs to — its language and `validAliasCharacters`. On a root set to `0-9a-z`
 * the bundle wrote aliases the installation forbids.
 */
class ContaoAliasTest extends TestCase
{
    private mixed $previousContainer = null;

    protected function setUp(): void
    {
        // Restored in tearDown(): other tests skip on a missing container and would
        // run against this empty one.
        $this->previousContainer = System::getContainer();

        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        System::setContainer($container);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(System::class, 'objContainer'))->setValue(null, $this->previousContainer);
        (new \ReflectionProperty(System::class, 'arrStaticObjects'))->setValue(null, []);
        unset($GLOBALS['TL_DCA']['tl_probe']);
        FakeAliasCallback::$seen = null;
    }

    public function testTheCallbackOfTheAliasFieldGeneratesIt(): void
    {
        $GLOBALS['TL_DCA']['tl_probe']['fields']['alias']['save_callback'] = [
            [FakeAliasCallback::class, 'generateAlias'],
        ];

        $alias = ContaoAlias::generate('tl_probe', ['title' => 'Über uns', 'pid' => 7]);

        $this->assertSame('slug:Über uns@7', $alias);
    }

    public function testTheCallbackReadsTheRecordAsActiveRecordAndGetsAnEmptyValue(): void
    {
        $GLOBALS['TL_DCA']['tl_probe']['fields']['alias']['save_callback'] = [
            [FakeAliasCallback::class, 'generateAlias'],
        ];

        ContaoAlias::generate('tl_probe', ['title' => 'Team', 'pid' => 3]);

        $this->assertSame(['', 'tl_probe', 0], FakeAliasCallback::$seen);
    }

    public function testOtherSaveCallbacksAreNotCalled(): void
    {
        $GLOBALS['TL_DCA']['tl_probe']['fields']['alias']['save_callback'] = [
            [FakeAliasCallback::class, 'somethingElse'],
        ];

        $this->assertNull(ContaoAlias::generate('tl_probe', ['title' => 'Team']));
    }

    public function testWithoutCallbackThereIsNoContaoAlias(): void
    {
        $GLOBALS['TL_DCA']['tl_probe']['fields']['alias'] = ['eval' => ['rgxp' => 'alias']];

        $this->assertNull(ContaoAlias::generate('tl_probe', ['title' => 'Team']));
    }
}

/**
 * Stands in for `tl_article::generateAlias()`: reads `$dc->activeRecord` and `$dc->id`
 * the way Contao's callbacks do.
 */
class FakeAliasCallback
{
    /** @var array{string, string, int}|null */
    public static ?array $seen = null;

    public function generateAlias(mixed $value, DataContainer $dc): string
    {
        self::$seen = [(string) $value, (string) $dc->table, (int) $dc->id];

        /** @var object{title: string, pid: int} $record */
        $record = $dc->__get('activeRecord');

        return 'slug:' . $record->title . '@' . $record->pid;
    }

    public function somethingElse(mixed $value): string
    {
        return 'nope';
    }
}
