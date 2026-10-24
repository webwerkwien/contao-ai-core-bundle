<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\EventListener;

use Contao\CoreBundle\Exception\AccessDeniedException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoAiCoreBundle\EventListener\CliUserProtectionListener;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;

class CliUserProtectionListenerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $dca = null;

    protected function setUp(): void
    {
        $this->dca = $GLOBALS['TL_DCA'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->dca) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $this->dca;
        }
    }

    private function listener(?string $query): CliUserProtectionListener
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('9');

        $requests = new RequestStack();

        if (null !== $query) {
            $requests->push(Request::create('/contao?' . $query));
        }

        return new CliUserProtectionListener($connection, $requests);
    }

    public function testTheUserIsNotListed(): void
    {
        $GLOBALS['TL_DCA']['tl_user']['list']['sorting'] = [];
        $GLOBALS['TL_DCA']['tl_page']['list']['sorting'] = [];

        $listener = $this->listener(null);
        $listener->hide('tl_user');
        $listener->hide('tl_page');

        $this->assertSame([['username!=?', CliUser::USERNAME]], $GLOBALS['TL_DCA']['tl_user']['list']['sorting']['filter']);
        $this->assertArrayNotHasKey('filter', $GLOBALS['TL_DCA']['tl_page']['list']['sorting']);
    }

    /**
     * @dataProvider locked
     */
    public function testChangingItIsRefusedForEveryone(string $act): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->listener('do=user&act=' . $act . '&id=9')->lock();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locked(): array
    {
        return ['edit' => ['edit'], 'delete' => ['delete'], 'copy' => ['copy'], 'toggle' => ['toggle'], 'cut' => ['cut']];
    }

    /**
     * The select mode keeps the IDs in the session, set by a POST.
     *
     * @param list<int> $ids
     */
    private function bulkListener(string $act, array $ids): CliUserProtectionListener
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('9');

        $request = Request::create('/contao?do=user&act=' . $act);
        $session = new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
        $session->set('CURRENT', ['IDS' => $ids]);
        $request->setSession($session);

        $requests = new RequestStack();
        $requests->push($request);

        return new CliUserProtectionListener($connection, $requests);
    }

    public function testASelectionThatContainsItIsRefused(): void
    {
        foreach (['editAll', 'overrideAll', 'deleteAll', 'copyAll', 'cutAll'] as $act) {
            try {
                $this->bulkListener($act, [3, 9])->lock();
                $this->fail($act . ' should be refused');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->bulkListener('editAll', [3, 4])->lock();
        $this->addToAssertionCount(1);
    }

    /**
     * Contao checks `disable` at login only, not when an admin switches.
     */
    public function testNobodySwitchesIntoIt(): void
    {
        $listener = $this->listener(null);
        $target   = new \Symfony\Component\Security\Core\User\InMemoryUser(CliUser::USERNAME, null);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);

        $listener->refuseSwitch(new \Symfony\Component\Security\Http\Event\SwitchUserEvent(new Request(), $target));
    }

    public function testSwitchingToSomeoneElseIsLeftAlone(): void
    {
        $this->listener(null)->refuseSwitch(new \Symfony\Component\Security\Http\Event\SwitchUserEvent(new Request(), new \Symfony\Component\Security\Core\User\InMemoryUser('editor', null)));

        $this->addToAssertionCount(1);
    }

    public function testOtherUsersAndReadingAreLeftAlone(): void
    {
        $this->listener('do=user&act=edit&id=3')->lock();
        $this->listener('do=user&act=show&id=9')->lock();
        $this->listener('do=user')->lock();
        // The console (our own commands) has no request.
        $this->listener(null)->lock();

        $this->addToAssertionCount(1);
    }
}
