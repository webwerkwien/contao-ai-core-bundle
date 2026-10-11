<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Session\Attribute\ArrayAttributeBag;
use Contao\DataContainer;
use Contao\StringUtil;
use Contao\System;
use Contao\Widget;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaPaletteCommand;
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
 * | `onbeforesubmit` | `$values, $dc`, returns the values | after the save_callbacks, before the UPDATE (v1.5.0) |
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
 *
 * Public API for extensions since v1.4.0: save() for the field callbacks before a
 * write through RecordWriterInterface (which runs the record events itself), and the
 * event methods for writes an extension makes on its own; drainReport() for what ran.
 *
 * @api
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
        ?\Closure $loadLanguageFile = null,
        ?\Closure $palette = null,
    ) {
        $this->loadLanguageFile = $loadLanguageFile ?? static function (string $name): void {
            System::loadLanguageFile($name);
        };
        $this->palette = $palette ?? static fn (DataContainer $dc): string => (string) $dc->getPalette();
    }

    /** @var \Closure(DataContainer): string only replaced in tests */
    private readonly \Closure $palette;

    /** @var \Closure(string): void only replaced in tests */
    private readonly \Closure $loadLanguageFile;

    /** The throw-away session of a console run, see withSession(). */
    private ?Session $consoleSession = null;

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

    /**
     * Before the record is saved, after the fields' save_callbacks — where
     * `DC_Table::submit()` runs `onbeforesubmit` (v1.5.0). Each callback gets
     * `($values, $dc)` and returns the values to write: it may change, add or remove
     * fields. Contao's StartStopValidationListener refuses a stop before the start here
     * (tl_page, tl_article, tl_content, tl_member, tl_user and their groups).
     *
     * As in DC_Table, the values carry `tstamp`, and nothing runs for empty values.
     * The data container's getCurrentRecord() is the stored record, the state being
     * replaced (for a create: the row as inserted); getActiveRecord() and `activeRecord`
     * carry the values being saved on top of it. Every value handed over counts as
     * submitted — DC_Table hands over only what changed, so a callback that checks
     * `array_key_exists` sees an unchanged `--set start=…` as set.
     *
     * @param array<string, mixed> $values the values about to be written
     * @param array<string, mixed> $record the stored record
     *
     * @return array<string, mixed> the values to write after the callbacks
     */
    public function beforeSubmit(string $table, int $id, array $values, array $record): array
    {
        $callbacks = $GLOBALS['TL_DCA'][$table]['config']['onbeforesubmit_callback'] ?? null;

        if ([] === $values || !\is_array($callbacks) || [] === $callbacks) {
            return $values;
        }

        return $this->withSession(function () use ($table, $id, $values, $record, $callbacks): array {
            ($this->loadLanguageFile)('default');
            ($this->loadLanguageFile)($table);

            $values['tstamp'] = time();
            // As in DC_Table: getCurrentRecord() is the state being replaced,
            // getActiveRecord() and `activeRecord` carry the values being saved.
            $dc = RecordDataContainer::create($table, $id, $record, $this->connection, $values);
            $name             = $table . '.onbeforesubmit';

            foreach ($callbacks as $callback) {
                $label = self::label($callback);

                try {
                    // In Contao's coercive mode, see CallbackInvoker.
                    $result = CallbackInvoker::call($callback, [$values, $dc], $values);
                } catch (ResponseException) {
                    $this->ran[] = $name . ': ' . $label . ' (answered with a redirect; the remaining callbacks did not run)';

                    return $values;
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException(\sprintf('%s of %s:%d refused by %s: %s Nothing was written.', $name, $table, $id, $label, $e->getMessage()), 0, $e);
                }

                // Contao's own words for the same defect.
                if (!\is_array($result)) {
                    throw new \RuntimeException(\sprintf('The onbeforesubmit_callback must return the values! (%s)', $label));
                }

                $values      = $result;
                $this->ran[] = $name . ': ' . $label;
            }

            return $values;
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
     * The save_callbacks of the fields about to be written, as `DC_Table::save()` runs
     * them (v1.4.0): field by field in DCA order, with `($value, $dc)`, the returned
     * value replacing the given one, and the data container's `field`, `inputName` and
     * active record set as the back end sets them — the active record takes each saved
     * value before the next field.
     *
     * The values arrive in their stored form (timestamps, serialized lists), which is
     * what DC_Table hands its callbacks too. A callback that throws refuses the write
     * with its own message, as the mask shows it under the field. Fields in $skip are
     * left alone: their callbacks already run on their own path (the password hash, the
     * page alias).
     *
     * @param array<string, mixed> $fields the values to write
     * @param array<string, mixed> $record the stored record, empty for a new one
     * @param list<string>         $skip
     * @param list<string>         $requested fields the caller set on purpose (`--set`, the
     *                                        field of a dedicated command): their callbacks run
     *                                        even where the palette on the console does not
     *                                        show them — a package may add a field to the
     *                                        palette only in its onload callback for the edit
     *                                        mask (changelanguage's languageMain)
     *
     * @return array<string, mixed> the values to write after the callbacks
     */
    public function save(string $table, int $id, array $fields, array $record, array $skip = [], array $requested = []): array
    {
        $definitions = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $withCallbacks = array_filter(
            array_keys($definitions),
            static fn (string $field): bool => \array_key_exists($field, $fields)
                && !\in_array($field, $skip, true)
                && \is_array($definitions[$field]['save_callback'] ?? null)
                && [] !== $definitions[$field]['save_callback'],
        );

        if ([] === $withCallbacks) {
            return $fields;
        }

        return $this->withSession(function () use ($table, $id, $fields, $record, $definitions, $withCallbacks, $requested): array {
            ($this->loadLanguageFile)('default');
            ($this->loadLanguageFile)($table);

            $dc = RecordDataContainer::create($table, $id, array_replace($record, $fields), $this->connection);

            // Only the fields of the record's palette, as only those are submitted in
            // the back end. A page create writes language '' for a non-root page, and
            // tl_page.language's callback refused it — the field is in the root
            // palette only, so the back end never runs it there (c5, 6.0, 2026-10-10).
            // Contao's own getPalette() decides, selectors and sub-palettes included.
            try {
                $inPalette = DcaPaletteCommand::fieldsOf((string) ($this->palette)($dc));
            } catch (\Throwable $e) {
                $this->ran[] = $table . ': the palette could not be built on the console (' . $e->getMessage() . '); only the save_callbacks of the fields set on purpose ran';
                $inPalette   = [];
            }

            // What the caller set on purpose is checked whatever the palette says: a
            // package can add a field to the palette in its onload callback for the edit
            // mask only, and changelanguage's duplicate check on languageMain was skipped
            // that way (c5, 6.0, 2026-10-10). What a command adds itself — language ''
            // on a regular page — only where the palette shows it.
            $withCallbacks = array_values(array_intersect($withCallbacks, [...$inPalette, ...$requested]));

            foreach ($withCallbacks as $field) {
                \Closure::bind(static function (DataContainer $dc, string $field): void {
                    $dc->strField     = $field;
                    $dc->strInputName = $field;
                }, null, DataContainer::class)($dc, $field);

                $value = $fields[$field];

                foreach ($definitions[$field]['save_callback'] as $callback) {
                    $label = self::label($callback);

                    try {
                        // In Contao's coercive mode, see CallbackInvoker.
                        $value = CallbackInvoker::call($callback, [$value, $dc], $value);
                    } catch (ResponseException) {
                        $this->ran[] = $table . '.' . $field . '.save: ' . $label . ' (answered with a redirect; ignored)';

                        continue;
                    } catch (\Exception $e) {
                        throw new \InvalidArgumentException(\sprintf('%s.%s refused by %s: %s Nothing was written.', $table, $field, $label, $e->getMessage()), 0, $e);
                    }

                    $this->ran[] = $table . '.' . $field . '.save: ' . $label;
                }

                // As DC_Table::save() after its callbacks: an empty value becomes the
                // empty value of the column type. 5.3's tl_page::checkFallback returns ''
                // for an unchecked box, and '' in its integer column was an SQL error (c5,
                // 5.3, 2026-10-10).
                if (!\is_array($value) && '' === (string) $value) {
                    $value = Widget::getEmptyValueByFieldType($definitions[$field]['sql'] ?? []);
                }

                $fields[$field] = $value;

                // As DC_Table::save(): the next field's callbacks see this one saved.
                \Closure::bind(static function (DataContainer $dc, string $field, mixed $value): void {
                    if (\is_object($dc->objActiveRecord)) {
                        $dc->objActiveRecord->{$field} = StringUtil::deserialize($value);
                    }
                }, null, DataContainer::class)($dc, $field, $value);
            }

            return $fields;
        });
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

    /** @var list<array{type: string, text: string}> */
    private array $messages = [];

    /**
     * The Contao messages the callbacks left since the last call (v1.6.0), in order:
     * `['type' => 'error', 'text' => '…']`. Types as Contao names them — error,
     * confirm, new, info, raw (a raw message as plain text).
     *
     * @return list<array{type: string, text: string}>
     */
    public function drainMessages(): array
    {
        $messages       = $this->messages;
        $this->messages = [];

        return $messages;
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

        // The labels the back end has loaded before DC_Table runs: `default` and the
        // table's own. On the console nothing is loaded, and the record preview of a
        // deleted page warned "Undefined array key selectNode" (c5, 6.0, 2026-10-10).
        ($this->loadLanguageFile)('default');
        ($this->loadLanguageFile)($table);

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
            return $this->collectingMessages($request->getSession(), fn () => $this->asUser(static fn () => $work($request)));
        }

        // One for the whole run, as the back end has one: oncreate lists the new page in
        // new_records, onsubmit's generateArticleForPage looks for it there. With a fresh
        // session per event the list was gone by then (c5, 6.0, 2026-10-10).
        if (null === $this->consoleSession) {
            $this->consoleSession = new Session(new MockArraySessionStorage());

            foreach (['contao_backend' => '_contao_be_attributes', 'contao_frontend' => '_contao_fe_attributes'] as $bagName => $storageKey) {
                $bag = new ArrayAttributeBag($storageKey);
                $bag->setName($bagName);
                $this->consoleSession->registerBag($bag);
            }
        }

        $request = Request::create('/');
        $request->setSession($this->consoleSession);
        // The scope the back end runs in; a callback may ask the scope matcher.
        $request->attributes->set('_scope', 'backend');

        $this->requestStack->push($request);

        try {
            return $this->collectingMessages($this->consoleSession, fn () => $this->asUser(static fn () => $work($request)));
        } finally {
            $this->requestStack->pop();
        }
    }

    /** The flash bag keys of Contao's messages: `contao.BE.error`, `contao.FE.info`, `contao..new`. */
    private const MESSAGE_KEY = '/^contao\.(?:BE|FE|)\.(error|confirm|new|info|raw)$/';

    /**
     * Takes the messages a callback leaves — `Message::addError()`, `addConfirmation()`
     * … — out of the session and into the report (v1.6.0).
     *
     * In the back end they show on the next page. Here nothing showed them: on the
     * console the session is thrown away, so a callback's *why* behind a refusal or
     * its notice about a side effect was lost; in the chat they stayed in the editor's
     * session and turned up later on some unrelated back-end page. Only what the
     * callbacks added is taken, also when they throw; what was there before stays.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function collectingMessages(SessionInterface $session, \Closure $work): mixed
    {
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return $work();
        }

        $flashBag = $session->getFlashBag();
        $before   = $flashBag->peekAll();

        try {
            return $work();
        } finally {
            foreach ($flashBag->peekAll() as $key => $messages) {
                if (1 !== preg_match(self::MESSAGE_KEY, (string) $key, $type) || !\is_array($messages)) {
                    continue;
                }

                $kept  = \is_array($before[$key] ?? null) ? $before[$key] : [];
                $added = \array_slice($messages, \count($kept));

                foreach ($added as $text) {
                    if (\is_scalar($text) && '' !== ($text = trim((string) $text))) {
                        $this->messages[] = ['type' => $type[1], 'text' => 'raw' === $type[1] ? self::plainText($text) : $text];
                    }
                }

                $flashBag->set((string) $key, $kept);
            }
        }
    }

    /** A raw message is HTML; the caller reads text. */
    private static function plainText(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
