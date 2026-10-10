<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Dca;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Session\Attribute\ArrayAttributeBag;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;

/**
 * The record callbacks run as DC_Table runs them (v1.4.0): arguments, data container,
 * session, and what a callback that fails or redirects does to the write.
 */
class DcaCallbacksTest extends TestCase
{
    private RequestStack $requests;

    private TokenStorage $tokens;

    /** @var array<string, mixed>|null */
    private ?array $dca = null;

    protected function setUp(): void
    {
        $this->dca      = $GLOBALS['TL_DCA'] ?? null;
        $this->requests = new RequestStack();
        $this->tokens   = new TokenStorage();
    }

    protected function tearDown(): void
    {
        if (null === $this->dca) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $this->dca;
        }
    }

    private function callbacks(array $row = ['id' => 7, 'pid' => 3, 'title' => 'Seite']): DcaCallbacks
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $connection->method('fetchAssociative')->willReturn($row);

        return new DcaCallbacks($connection, $this->requests, null, $this->tokens);
    }

    /**
     * @param list<callable> $callbacks
     */
    private function on(string $event, array $callbacks): void
    {
        $GLOBALS['TL_DCA']['tl_page']['config'][$event] = $callbacks;
    }

    public function testEachEventHandsOverWhatDcTableHandsOver(): void
    {
        $seen = [];
        $this->on('oncreate_callback', [static function (string $table, int $id, array $set, DataContainer $dc) use (&$seen): void {
            $seen['create'] = [$table, $id, $set['title'], $dc->id, $dc->getActiveRecord()['title']];
        }]);
        $this->on('onsubmit_callback', [static function (DataContainer $dc) use (&$seen): void {
            $seen['submit'] = [$dc->table, $dc->id, $dc->getActiveRecord()['pid']];
        }]);
        $this->on('oncopy_callback', [static function (int $newId, DataContainer $dc) use (&$seen): void {
            $seen['copy'] = [$newId, $dc->id];
        }]);
        $this->on('oncut_callback', [static function (DataContainer $dc) use (&$seen): void {
            $seen['cut'] = [$dc->id];
        }]);
        $this->on('ondelete_callback', [static function (DataContainer $dc, int $undoId) use (&$seen): void {
            $seen['delete'] = [$dc->id, $undoId, $dc->getActiveRecord()['title']];
        }]);

        $callbacks = $this->callbacks();
        $callbacks->create('tl_page', 7, ['id' => 7, 'title' => 'Neu']);
        $callbacks->submit('tl_page', 7);
        $callbacks->copy('tl_page', 9, 7);
        $callbacks->cut('tl_page', 7);
        $callbacks->delete('tl_page', 7, ['id' => 7, 'title' => 'Weg'], 41);

        $this->assertSame([
            'create' => ['tl_page', 7, 'Neu', 7, 'Neu'],
            'submit' => ['tl_page', 7, 3],
            // The data container of a copy is the source, as in DC_Table::copy().
            'copy'   => [9, 7],
            'cut'    => [7],
            'delete' => [7, 41, 'Weg'],
        ], $seen);
    }

    public function testTheConsoleGetsAThrowAwaySessionWithContaosBagsAndLosesItAfterwards(): void
    {
        $requests = $this->requests;
        $seen     = null;

        // tl_page::scheduleUpdate writes to the session on every page save.
        $this->on('onsubmit_callback', [static function () use ($requests, &$seen): void {
            $session = $requests->getCurrentRequest()?->getSession();
            $session?->set('sitemap_updater', [1]);
            $seen = [$session?->getBag('contao_backend') instanceof ArrayAttributeBag, $requests->getCurrentRequest()?->attributes->get('_scope')];
        }]);

        $this->callbacks()->submit('tl_page', 7);

        $this->assertSame([true, 'backend'], $seen);
        $this->assertNull($this->requests->getCurrentRequest());
    }

    public function testACreateIsListedAsNewInTheSessionOfTheChat(): void
    {
        // generateArticleForPage acts only on a page listed under new_records.
        $session = new Session(new MockArraySessionStorage());
        $bag     = new ArrayAttributeBag('_contao_be_attributes');
        $bag->setName('contao_backend');
        $session->registerBag($bag);

        $request = Request::create('/contao/ai');
        $request->setSession($session);
        $this->requests->push($request);
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('editor', null), 'contao_backend'));

        $this->callbacks()->create('tl_page', 7, ['id' => 7]);
        $this->callbacks()->create('tl_page', 8, ['id' => 8]);

        $this->assertSame(['tl_page' => [7, 8]], $bag->get('new_records'));
        $this->assertSame($request, $this->requests->getCurrentRequest());
    }

    public function testWithoutAUserNothingIsListedAsNew(): void
    {
        // The 5.3 adjustPermissions callbacks read the groups of the user of a listed
        // record; for nobody that was a syntax error (c5, 5.3).
        $requests = $this->requests;
        $listed   = 'not read';
        $this->on('oncreate_callback', [static function () use ($requests, &$listed): void {
            $listed = $requests->getCurrentRequest()?->getSession()->getBag('contao_backend')->get('new_records');
        }]);

        $this->callbacks()->create('tl_page', 7, ['id' => 7]);

        $this->assertNull($listed);
    }

    public function testAThrowingCallbackRefusesTheWriteAndNamesItself(): void
    {
        $cause = new \RuntimeException('Zweite Fallback-Seite.');
        $this->on('onsubmit_callback', [static function () use ($cause): void {
            throw $cause;
        }]);

        try {
            $this->callbacks()->submit('tl_page', 7);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            // Exactly the refusal class, so the error boundary reports no defect.
            $this->assertSame(\InvalidArgumentException::class, $e::class);
            $this->assertStringContainsString('tl_page.onsubmit of tl_page:7 refused by closure in ' . self::class, $e->getMessage());
            $this->assertStringContainsString('Zweite Fallback-Seite. Nothing was written.', $e->getMessage());
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    /**
     * Contao calls in coercive mode (DC_Table has no strict_types): RecordPreviewListener
     * types `string $undoId`, an extension may type `int $insertId`. From a strict caller
     * both were TypeErrors — the first delete on c5 (6.0) on 2026-10-10 (CallbackInvoker).
     */
    public function testCallbacksAreCalledInContaosCoerciveMode(): void
    {
        $seen = [];
        $this->on('ondelete_callback', [static function (DataContainer $dc, string $undoId) use (&$seen): void {
            $seen[] = $undoId;
        }]);
        $this->on('oncopy_callback', [static function (string $newId, DataContainer $dc) use (&$seen): void {
            $seen[] = $newId;
        }]);

        $callbacks = $this->callbacks();
        $callbacks->delete('tl_page', 7, ['id' => 7], 41);
        $callbacks->copy('tl_page', 9, 7);

        $this->assertSame(['41', '9'], $seen);
    }

    public function testAnErrorInACallbackIsADefectAndPassesThrough(): void
    {
        $this->on('ondelete_callback', [static function (): void {
            throw new \TypeError('a bug');
        }]);

        $this->expectException(\TypeError::class);

        $this->callbacks()->delete('tl_page', 7, ['id' => 7], 1);
    }

    public function testARedirectEndsTheEventButNotTheWrite(): void
    {
        $ran = [];
        $this->on('oncut_callback', [
            static function () use (&$ran): void { $ran[] = 'first'; },
            static function (): void { throw new RedirectResponseException('/contao'); },
            static function () use (&$ran): void { $ran[] = 'never'; },
        ]);

        $callbacks = $this->callbacks();
        $callbacks->cut('tl_page', 7);

        $this->assertSame(['first'], $ran);

        $report = $callbacks->drainReport();
        $this->assertCount(2, $report);
        $this->assertStringContainsString('answered with a redirect', $report[1]);
    }

    public function testTheReportNamesWhatRanAndIsDrained(): void
    {
        $this->on('onsubmit_callback', [static function (): void {}]);

        $callbacks = $this->callbacks();
        $callbacks->submit('tl_page', 7);

        $this->assertSame(['tl_page.onsubmit: closure in ' . self::class], $callbacks->drainReport());
        $this->assertSame([], $callbacks->drainReport());
    }

    public function testWithoutCallbacksNothingRunsAndNoSessionIsMade(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['config'] = [];

        $callbacks = $this->callbacks();
        $callbacks->submit('tl_page', 7);

        $this->assertSame([], $callbacks->drainReport());
    }
}
