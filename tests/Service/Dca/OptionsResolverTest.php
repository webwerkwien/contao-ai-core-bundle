<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Dca;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;

/**
 * The options a field offers, asked from the field's own options callback.
 *
 * 🟡 **Measured on Contao 5.7.13** before v1.0:
 *
 * - `schema resolve tl_page type` listed ten core page types. `consho_product` was
 *   missing, although the installation had two pages of that type. The CLI answered from a
 *   **hard-coded list**; page types a bundle registers through `#[AsPage]` come from
 *   the `PageRegistry`, which only Contao's `PageTypeOptionsListener` asks.
 * - `schema resolve tl_content customTpl` answered `__unresolved__`, and
 *   `--set customTpl=content_element/text/gibtesnicht` was stored with `ok`.
 *
 * The resolver calls the callback the way the back end does — with a DataContainer
 * built without its constructor (no request, no back-end user), carrying the table,
 * an id and the active record, as CacheTagInvalidator does for its callbacks. Both
 * listeners above work that way: `PageTypeOptionsListener` returns its full list when
 * there is no current record (the help-wizard case, contao/contao#7137), and
 * `TemplateOptionsListener` reads the element type from `getActiveRecord()`.
 *
 * A callback that needs more — a request, a user, the voters — throws; that is
 * reported as unresolvable, never guessed.
 */
class OptionsResolverTest extends TestCase
{
    private function resolver(): OptionsResolver
    {
        return new OptionsResolver($this->createMock(ContaoFramework::class));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    public function testACallbackIsAskedAndItsValuesReturned(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['type'] = [
            'options_callback' => static fn (DataContainer $dc): array => ['regular', 'root', 'consho_product'],
        ];

        $this->assertSame(['regular', 'root', 'consho_product'], $this->resolver()->values('tl_test', 'type'));
    }

    public function testTheCallbackSeesTheActiveRecord(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['customTpl'] = [
            'options_callback' => static fn (DataContainer $dc): array => 'text' === ($dc->getActiveRecord()['type'] ?? null)
                ? ['content_element/text' => 'text', 'content_element/text/hero' => 'text (hero)']
                : [],
        ];

        $this->assertSame(
            ['content_element/text', 'content_element/text/hero'],
            $this->resolver()->values('tl_test', 'customTpl', ['type' => 'text']),
        );
    }

    public function testGroupsAreFlattened(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['size'] = [
            'options_callback' => static fn (): array => ['Theme' => ['6' => 'Hero'], 'Modes' => ['proportional' => 'Proportional']],
        ];

        $this->assertSame(['6', 'proportional'], $this->resolver()->values('tl_test', 'size'));
    }

    public function testACallbackThatNeedsMoreThanTheConsoleHasIsUnresolvable(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['groups'] = [
            'options_callback' => static fn (): array => throw new \RuntimeException('no request on the console'),
        ];

        $resolver = $this->resolver();

        $this->assertNull($resolver->values('tl_test', 'groups'));
        $this->assertStringContainsString('no request on the console', (string) $resolver->lastError('tl_test', 'groups'));
    }

    public function testStaticOptionsNeedNoCallback(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['sitemap'] = ['options' => ['map_default', 'map_never']];

        $this->assertSame(['map_default', 'map_never'], $this->resolver()->values('tl_test', 'sitemap'));
    }

    /**
     * `eval.isAssociative` has to reach the reading here too, or `dca:options`
     * answers the labels while `dca:schema` answers the indices (before v0.21.1).
     */
    public function testAListDeclaredAssociativeGivesItsIndices(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['useSSL'] = ['options' => ['http://', 'https://'], 'eval' => ['isAssociative' => true]];

        $this->assertSame(['0', '1'], $this->resolver()->values('tl_test', 'useSSL'));
    }

    /**
     * A permission-filtered callback does not throw on the console, it answers
     * empty — Contao's tl_content.getForms() returns [] for a non-admin, and without
     * a token BackendUser is an empty non-admin. A caller then refused every form,
     * although the write itself runs as `contao-ai`. The callback sees the same user
     * as the write (since v1.4.2).
     */
    public function testOnTheConsoleTheCallbackSeesTheUserTheWriteRunsAs(): void
    {
        $tokens   = new TokenStorage();
        $user     = $this->createMock(BackendUser::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_ADMIN']);
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->with(CliUser::USERNAME)->willReturn($user);

        $GLOBALS['TL_DCA']['tl_test']['fields']['form'] = [
            'options_callback' => static fn (): array => $tokens->getToken()?->getUser() === $user ? ['1', '2'] : [],
        ];

        $resolver = new OptionsResolver($this->createMock(ContaoFramework::class), new CliUser($tokens, $provider));

        $this->assertSame(['1', '2'], $resolver->values('tl_test', 'form'));
        $this->assertNull($tokens->getToken());
    }

    public function testAnUnloadableTableIsUnresolvableAndDoesNotThrow(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('initialize')->willThrowException(new \RuntimeException('no DCA here'));

        $resolver = new OptionsResolver($framework);

        $this->assertNull($resolver->values('tl_nope', 'field'));
        $this->assertStringContainsString('no DCA here', (string) $resolver->lastError('tl_nope', 'field'));
    }

    public function testAFieldWithoutOptionsHasNone(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields']['title'] = ['inputType' => 'text'];

        $this->assertNull($this->resolver()->values('tl_test', 'title'));
    }
}
