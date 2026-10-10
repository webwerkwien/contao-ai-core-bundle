<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Session\Attribute\ArrayAttributeBag;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;

/**
 * The record callbacks of a DCA, called where `DC_Table` calls them (v1.4.0).
 *
 * 🎯 **Until v1.4.0 the write paths of this bundle went past them.** `Model::save()`
 * and a plain DELETE run no DCA callback, so whatever a table hangs on create, save,
 * copy, cut or delete did not happen: the search index kept deleted pages, a comment
 * freed through the bundle sent no notification, and an extension's rules — Consho's
 * shop sync on `tl_page`, changelanguage's link reset — were skipped without a word.
 * Measured on c5 on 2026-10-10 in 5.3, 5.7 and 6.0: 121 callbacks of Contao and 10 of
 * Consho on the 25 tables this bundle writes, of which the bundle reproduced six by
 * hand.
 *
 * Signatures and timing as in `DC_Table` (identical in 5.3, 5.7 and 6.0):
 *
 * | Event      | Called with                      | When                                         |
 * |------------|----------------------------------|----------------------------------------------|
 * | `oncreate` | `$table, $id, $set, $dc`         | right after the INSERT                       |
 * | `onsubmit` | `$dc`                            | after the UPDATE, before the new version     |
 * | `oncopy`   | `$newId, $dc` (dc = the source)  | per child after its INSERT, the root last    |
 * | `oncut`    | `$dc`                            | after the UPDATE of pid/sorting              |
 * | `ondelete` | `$dc, $undoId`                   | after the tl_undo row, before the DELETE     |
 *
 * **A session is always there.** Callbacks reach for it — `tl_page::scheduleUpdate`
 * writes to it on every save, cut and delete of a page, and the console has none, so
 * every page write would fail on `SessionNotFoundException`. In the back end's chat
 * the user's real session is used; on the console a throw-away one, with Contao's two
 * attribute bags. What a callback leaves in it is discarded.
 *
 * **A user too.** Contao's callbacks assume one (`generateArticleForPage`, the record
 * preview, the 5.3 `adjustPermissions`). On the console they see `contao-ai`, an admin
 * that cannot log in (CliUser); in the chat the logged-in editor.
 *
 * **Failures.** A callback that throws an `\Exception` refuses the write: it comes
 * back as `\InvalidArgumentException` naming the callback, and the caller's
 * transaction rolls back — a failing `save_callback` stops the save in the back end as
 * well. An `\Error` is a defect and passes through unchanged. A callback that answers
 * with a response (a redirect) ends the event, as the redirect ends the request in the
 * back end; the write stands.
 */
class DcaCallbacks
{
    /** @var list<string> */
    private array $ran = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly ?CliUser $cliUser = null,
        private readonly ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * After a record was inserted. Registers it as new in the session, as
     * `DC_Table::create()` does — `generateArticleForPage` only acts on a page listed
     * there.
     *
     * @param array<string, mixed>|null $set the values the record was inserted with; read
     *                                       from the database when not given
     */
    public function create(string $table, int $id, ?array $set = null): void
    {
        $set ??= $this->row($table, $id);

        $this->withSession(function (Request $request) use ($table, $id, $set): void {
            $bag = $request->getSession()->getBag('contao_backend');

            // Only while a user is logged in — the editor in the chat, contao-ai on the
            // console. The 5.3 adjustPermissions callbacks act on a record listed there
            // and read the user's groups; for nobody, BackendUser::getInstance() is an
            // empty user and the query built from its groups was a syntax error (c5, 5.3,
            // 2026-10-10). Unlisted, they return early, as for a record they never saw.
            if (null !== $this->tokens?->getToken() && $bag instanceof ArrayAttributeBag) {
                $new = (array) $bag->get('new_records');
                $new[$table][] = $id;
                $bag->set('new_records', $new);
            }

            $this->run($table, 'oncreate_callback', $id, $set, fn (DataContainer $dc): array => [$table, $id, $set, $dc]);
        });
    }

    /** After the record was saved. */
    public function submit(string $table, int $id): void
    {
        $this->withSession(fn () => $this->run($table, 'onsubmit_callback', $id, $this->row($table, $id), static fn (DataContainer $dc): array => [$dc]));
    }

    /** After a copy of $sourceId was inserted as $newId; the data container is the source. */
    public function copy(string $table, int $newId, int $sourceId): void
    {
        $this->withSession(fn () => $this->run($table, 'oncopy_callback', $sourceId, $this->row($table, $sourceId), static fn (DataContainer $dc): array => [$newId, $dc]));
    }

    /** After the record was moved. */
    public function cut(string $table, int $id): void
    {
        $this->withSession(fn () => $this->run($table, 'oncut_callback', $id, $this->row($table, $id), static fn (DataContainer $dc): array => [$dc]));
    }

    /**
     * Before the record is deleted, after its undo entry was written.
     *
     * @param array<string, mixed> $row the record as it is about to be deleted
     */
    public function delete(string $table, int $id, array $row, int $undoId): void
    {
        $this->withSession(fn () => $this->run($table, 'ondelete_callback', $id, $row, static fn (DataContainer $dc): array => [$dc, $undoId]));
    }

    /**
     * The callbacks that ran since the last call, `tl_page.ondelete: tl_page::scheduleUpdate`.
     *
     * @return list<string>
     */
    public function drainReport(): array
    {
        $ran       = $this->ran;
        $this->ran = [];

        return $ran;
    }

    /**
     * @param array<string, mixed>                    $row
     * @param \Closure(DataContainer): list<mixed> $arguments
     */
    private function run(string $table, string $event, int $id, array $row, \Closure $arguments): void
    {
        $callbacks = $GLOBALS['TL_DCA'][$table]['config'][$event] ?? null;

        if (!\is_array($callbacks) || [] === $callbacks) {
            return;
        }

        $dc   = RecordDataContainer::create($table, $id, $row, $this->connection);
        $args = $arguments($dc);
        $name = $table . '.' . substr($event, 0, -\strlen('_callback'));

        foreach ($callbacks as $callback) {
            $label = self::label($callback);

            try {
                // In Contao's coercive mode, see CallbackInvoker.
                if (!CallbackInvoker::invoke($callback, $args)) {
                    continue;
                }
            } catch (ResponseException) {
                $this->ran[] = $name . ': ' . $label . ' (answered with a redirect; the remaining callbacks did not run, as in the back end)';

                return;
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(\sprintf('%s of %s:%d refused by %s: %s Nothing was written.', $name, $table, $id, $label, $e->getMessage()), 0, $e);
            }

            $this->ran[] = $name . ': ' . $label;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]);

        return \is_array($row) ? $row : [];
    }

    /**
     * @template T
     *
     * @param \Closure(Request): T $work
     *
     * @return T
     */
    private function withSession(\Closure $work): mixed
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request && $request->hasSession()) {
            return $this->asUser(static fn () => $work($request));
        }

        $session = new Session(new MockArraySessionStorage());

        foreach (['contao_backend' => '_contao_be_attributes', 'contao_frontend' => '_contao_fe_attributes'] as $bagName => $storageKey) {
            $bag = new ArrayAttributeBag($storageKey);
            $bag->setName($bagName);
            $session->registerBag($bag);
        }

        $request = Request::create('/');
        $request->setSession($session);
        // The scope the back end runs in; a callback may ask the scope matcher.
        $request->attributes->set('_scope', 'backend');

        $this->requestStack->push($request);

        try {
            return $this->asUser(static fn () => $work($request));
        } finally {
            $this->requestStack->pop();
        }
    }

    /**
     * On the console the callbacks see `contao-ai`; where someone is logged in, that
     * someone (CliUser).
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function asUser(\Closure $work): mixed
    {
        return null === $this->cliUser ? $work() : $this->cliUser->asCurrentUser($work);
    }

    private static function label(mixed $callback): string
    {
        if (\is_array($callback)) {
            return (\is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]) . '::' . $callback[1];
        }

        if ($callback instanceof \Closure) {
            $reflection = new \ReflectionFunction($callback);
            $scope      = $reflection->getClosureScopeClass();

            return 'closure in ' . ($scope?->getName() ?? 'global scope');
        }

        return \is_string($callback) ? $callback : get_debug_type($callback);
    }
}
