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

    /** The palette Contao's getPalette() would build; save callbacks run only for its fields. */
    private string $palette = '{title_legend},type,headline;{video_legend},youtube;{root_legend},fallback,dns,alias';

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

        $palette = $this->palette;

        return new DcaCallbacks($connection, $this->requests, null, $this->tokens, static function (): void {}, static fn (): string => $palette);
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

    public function testOnTheConsoleOneSessionSpansTheEventsOfARun(): void
    {
        // oncreate lists the page, onsubmit's generateArticleForPage reads the list.
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('contao-ai', null), 'contao_backend'));

        $requests = $this->requests;
        $listed   = null;
        $this->on('onsubmit_callback', [static function () use ($requests, &$listed): void {
            $listed = $requests->getCurrentRequest()?->getSession()->getBag('contao_backend')->get('new_records');
        }]);

        $callbacks = $this->callbacks();
        $callbacks->create('tl_page', 7, ['id' => 7]);
        $callbacks->submit('tl_page', 7);

        $this->assertSame(['tl_page' => [7]], $listed);
        $this->assertNull($this->requests->getCurrentRequest());
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

    /**
     * v1.6.0: what a callback tells the editor through Contao's messages comes back in
     * the report, in order; a raw message as text.
     */
    public function testTheCallbacksMessagesAreCollectedAndDrained(): void
    {
        $requests = $this->requests;

        $this->on('onsubmit_callback', [static function () use ($requests): void {
            $flashes = $requests->getSession()->getFlashBag();
            $flashes->add('contao.BE.info', 'Sitemap wird neu erstellt.');
            $flashes->add('contao.BE.error', 'Alias bereits vergeben.');
            $flashes->add('contao.BE.raw', '<p>Mit <strong>HTML</strong> &amp; Entity</p>');
            $flashes->add('some.other.key', 'not a Contao message');
        }]);

        $callbacks = $this->callbacks();
        $callbacks->submit('tl_page', 7);

        $this->assertSame([
            ['type' => 'info', 'text' => 'Sitemap wird neu erstellt.'],
            ['type' => 'error', 'text' => 'Alias bereits vergeben.'],
            ['type' => 'raw', 'text' => 'Mit HTML & Entity'],
        ], $callbacks->drainMessages());
        $this->assertSame([], $callbacks->drainMessages());
    }

    public function testAMessageOfARefusingCallbackIsCollectedToo(): void
    {
        $requests = $this->requests;

        $this->on('onsubmit_callback', [static function () use ($requests): void {
            $requests->getSession()->getFlashBag()->add('contao.BE.error', 'Startdatum liegt nach dem Enddatum.');

            throw new \RuntimeException('refused');
        }]);

        $callbacks = $this->callbacks();

        try {
            $callbacks->submit('tl_page', 7);
            $this->fail('the callback refuses');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([['type' => 'error', 'text' => 'Startdatum liegt nach dem Enddatum.']], $callbacks->drainMessages());
    }

    /**
     * In the chat the session is the editor's: what the callbacks added is taken out
     * of it — it would turn up on the next back-end page — and what was there stays.
     */
    public function testInTheChatOnlyTheCallbacksMessagesLeaveTheEditorsSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('contao.BE.confirm', 'Vorher gespeichert.');

        $request = Request::create('/contao/ai');
        $request->setSession($session);
        $this->requests->push($request);

        $this->on('onsubmit_callback', [static function () use ($session): void {
            $session->getFlashBag()->add('contao.BE.confirm', 'Vom Callback.');
            $session->getFlashBag()->add('contao.BE.new', 'Neu angelegt.');
        }]);

        $callbacks = $this->callbacks();
        $callbacks->submit('tl_page', 7);

        $this->assertSame([['type' => 'confirm', 'text' => 'Vom Callback.'], ['type' => 'new', 'text' => 'Neu angelegt.']], $callbacks->drainMessages());
        $this->assertSame(['Vorher gespeichert.'], $session->getFlashBag()->peek('contao.BE.confirm'));
        $this->assertSame([], $session->getFlashBag()->peek('contao.BE.new'));
    }

    public function testSaveCallbacksRunAsDcTableSaveRunsThem(): void
    {
        $seen = [];
        $GLOBALS['TL_DCA']['tl_content']['fields'] = [
            // DCA order, not the order of the given fields.
            'youtube' => ['save_callback' => [static function ($value, DataContainer $dc) use (&$seen) {
                $seen[] = ['youtube', $dc->field, $dc->inputName, $dc->getActiveRecord()['type']];

                return 'dQw4w9WgXcQ';
            }]],
            'type'    => ['save_callback' => [static function ($value, DataContainer $dc) use (&$seen) {
                // The previous field is saved in the active record object already, as
                // DC_Table::save() keeps it (the deprecated `$dc->activeRecord`).
                $active = (fn () => $this->objActiveRecord)->call($dc);
                $seen[] = ['type', $dc->field, $active->youtube];

                return $value;
            }]],
            'headline' => [],
        ];

        $fields = $this->callbacks()->save('tl_content', 12, ['type' => 'youtube', 'youtube' => 'https://youtu.be/dQw4w9WgXcQ', 'headline' => 'x'], ['id' => 12, 'type' => 'text']);

        $this->assertSame(['type' => 'youtube', 'youtube' => 'dQw4w9WgXcQ', 'headline' => 'x'], $fields);
        $this->assertSame([['youtube', 'youtube', 'youtube', 'youtube'], ['type', 'type', 'dQw4w9WgXcQ']], $seen);
    }

    public function testASaveCallbackThatThrowsRefusesWithItsOwnMessage(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['fields']['fallback']['save_callback'] = [static function (): void {
            throw new \Exception('Es darf nur eine Fallback-Seite pro Domain geben.');
        }];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tl_page.fallback refused by closure in ' . self::class . ': Es darf nur eine Fallback-Seite pro Domain geben. Nothing was written.');

        $this->callbacks()->save('tl_page', 0, ['fallback' => '1'], []);
    }

    public function testFieldsOnTheirOwnPathAreSkippedAndUntouchedFieldsToo(): void
    {
        $ran = [];
        $GLOBALS['TL_DCA']['tl_page']['fields'] = [
            'alias' => ['save_callback' => [static function ($v) use (&$ran) { $ran[] = 'alias'; return $v; }]],
            'dns'   => ['save_callback' => [static function ($v) use (&$ran) { $ran[] = 'dns'; return $v; }]],
        ];

        $callbacks = $this->callbacks();
        $this->assertSame(['alias' => 'neu'], $callbacks->save('tl_page', 7, ['alias' => 'neu'], [], ['alias']));
        $this->assertSame([], $ran);
        $this->assertSame([], $callbacks->drainReport());
    }

    /**
     * The back end submits only the fields of the record's palette. tl_page.language is
     * in the root palette only; a page create writes '' for it on a regular page, and
     * its callback refused that (c5, 6.0, 2026-10-10).
     */
    public function testOnlyFieldsOfThePaletteRunTheirSaveCallbacks(): void
    {
        $ran = [];
        $GLOBALS['TL_DCA']['tl_page']['fields'] = [
            'language' => ['save_callback' => [static function () use (&$ran) { $ran[] = 'language'; throw new \Exception('invalid'); }]],
            'dns'      => ['save_callback' => [static function ($v) use (&$ran) { $ran[] = 'dns'; return $v; }]],
        ];

        $fields = $this->callbacks()->save('tl_page', 0, ['language' => '', 'dns' => 'example.org'], []);

        $this->assertSame(['language' => '', 'dns' => 'example.org'], $fields);
        $this->assertSame(['dns'], $ran);
    }

    /**
     * changelanguage adds languageMain to the page palette in its onload callback for the
     * edit mask only; on the console its duplicate check was skipped (c5, 6.0).
     */
    public function testAFieldSetOnPurposeRunsItsCallbacksOutsideThePaletteToo(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['fields']['languageMain']['save_callback'] = [static function (): void {
            throw new \RuntimeException('Another page in this tree is linked to it already.');
        }];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Another page in this tree is linked to it already.');

        $this->callbacks()->save('tl_page', 85, ['languageMain' => 73], ['id' => 85], [], ['languageMain']);
    }

    public function testWithoutAPaletteOnlyFieldsSetOnPurposeRunAndTheReportSaysSo(): void
    {
        $ran = [];
        $GLOBALS['TL_DCA']['tl_page']['fields']['dns']['save_callback'] = [static function (): void {
            throw new \Exception('must not run');
        }];
        $GLOBALS['TL_DCA']['tl_page']['fields']['title']['save_callback'] = [static function ($v) use (&$ran) {
            $ran[] = 'title';

            return $v;
        }];

        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn([]);
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);

        $callbacks = new DcaCallbacks($connection, $this->requests, null, $this->tokens, static function (): void {}, static function (): string {
            throw new \RuntimeException('no request');
        });

        $this->assertSame(['dns' => 'x', 'title' => 'T'], $callbacks->save('tl_page', 0, ['dns' => 'x', 'title' => 'T'], [], [], ['title']));
        $this->assertSame(['title'], $ran);
        $this->assertStringContainsString('the palette could not be built on the console (no request)', $callbacks->drainReport()[0]);
    }

    /**
     * 5.3's tl_page::checkFallback returns '' for an unchecked box; DC_Table turns that
     * into the empty value of the column, and '' in an integer column was an SQL error.
     */
    public function testAnEmptyValueBecomesTheEmptyValueOfItsColumn(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['fields']['fallback'] = [
            'sql'           => ['type' => 'boolean', 'default' => false],
            'save_callback' => [static fn () => ''],
        ];

        $fields = $this->callbacks()->save('tl_page', 7, ['fallback' => '0'], ['id' => 7]);

        $this->assertNotSame('', $fields['fallback']);
        $this->assertFalse((bool) $fields['fallback']);
    }

    public function testWithoutCallbacksNothingRunsAndNoSessionIsMade(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['config'] = [];

        $callbacks = $this->callbacks();
        $callbacks->submit('tl_page', 7);

        $this->assertSame([], $callbacks->drainReport());
    }

    /**
     * onbeforesubmit as DC_Table::submit() runs it (v1.5.0): ($values, $dc), the values
     * carry tstamp, the return value replaces them — a callback may change, add and
     * remove fields (Consho converts a virtual gross amount into net and drops it).
     */
    public function testBeforeSubmitHandsOverTheValuesAndTakesWhatItReturns(): void
    {
        $seen = null;
        $this->on('onbeforesubmit_callback', [
            static function (array $values, DataContainer $dc) use (&$seen): array {
                // getCurrentRecord(): the stored state; getActiveRecord(): with the new values, as in DC_Table.
                $seen = [isset($values['tstamp']), $values['amount'], $dc->id, $dc->getCurrentRecord()['title'], $dc->getActiveRecord()['amount'], $dc->__get('activeRecord')->title];
                $values['net'] = $values['amount'] / 1.2;
                unset($values['amount']);

                return $values;
            },
            static fn (array $values): array => $values + ['currency' => 'EUR'],
        ]);

        $callbacks = $this->callbacks();
        $values    = $callbacks->beforeSubmit('tl_page', 7, ['amount' => 12.0], ['id' => 7, 'title' => 'Alt']);

        $this->assertSame([true, 12.0, 7, 'Alt', 12.0, 'Alt'], $seen);
        $this->assertSame(['tstamp', 'net', 'currency'], array_keys($values));
        $this->assertEqualsWithDelta(10.0, $values['net'], 0.0001);
        $this->assertCount(2, $callbacks->drainReport());
    }

    public function testBeforeSubmitRunsForNothingWhenThereAreNoValues(): void
    {
        // DC_Table: `if (!$this->noReload && !empty($arrValues))` — an update that
        // only versions the record does not submit anything.
        $this->on('onbeforesubmit_callback', [static fn (): array => throw new \LogicException('must not run')]);

        $this->assertSame([], $this->callbacks()->beforeSubmit('tl_page', 7, [], ['id' => 7]));
    }

    public function testABeforeSubmitCallbackThatThrowsRefusesTheWrite(): void
    {
        // Contao's StartStopValidationListener throws a RuntimeException for a stop
        // before the start; the mask shows it as an error and saves nothing.
        $this->on('onbeforesubmit_callback', [static fn (): array => throw new \RuntimeException('Das Ende liegt vor dem Beginn.')]);

        try {
            $this->callbacks()->beforeSubmit('tl_page', 7, ['stop' => '1'], ['id' => 7]);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(\InvalidArgumentException::class, $e::class);
            $this->assertStringContainsString('tl_page.onbeforesubmit of tl_page:7 refused by', $e->getMessage());
            $this->assertStringContainsString('Das Ende liegt vor dem Beginn. Nothing was written.', $e->getMessage());
        }
    }

    public function testABeforeSubmitCallbackThatReturnsNoArrayIsADefect(): void
    {
        $this->on('onbeforesubmit_callback', [static function (array $values): void {}]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return the values');

        $this->callbacks()->beforeSubmit('tl_page', 7, ['title' => 'x'], ['id' => 7]);
    }
}
