<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\Database;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\StringUtil;
use Contao\Validator;
use Contao\Widget;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\ContaoAlias;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\StructuredFields;
use Webwerkwien\ContaoAiCoreBundle\Service\Sorting;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\RecordWriterInterface;

abstract class AbstractWriteCommand extends Command
{
    use InvalidatesCacheTags;
    use JsonErrorBoundary;
    use ReadsDcaOptions;

    protected InputInterface $input;
    protected OutputInterface $output;
    protected VersionManager $versionManager;
    protected LoggerInterface $logger;

    /**
     * Nullable on purpose: the command tests construct commands by hand, without
     * a container. In the container it is always injected (see setSystemLog).
     */
    protected ?SystemLog $systemLog = null;

    #[Required]
    public function setVersionManager(VersionManager $versionManager): void
    {
        $this->versionManager = $versionManager;
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[Required]
    public function setSystemLog(SystemLog $systemLog): void
    {
        $this->systemLog = $systemLog;
    }

    /**
     * The context for a line this command writes to one of Contao's own channels.
     *
     * Without it, a line written on the console is labelled FE / N/A: Contao's
     * processor fills the columns from the request, and there is none (Nr. 59,
     * Nr. 66). Empty without a SystemLog, as in the hand-built command tests.
     *
     * @return array{contao?: \Contao\CoreBundle\Monolog\ContaoContext}
     */
    protected function logContext(string $action): array
    {
        return null === $this->systemLog
            ? []
            : ['contao' => $this->systemLog->context((string) $this->getName(), $this->resolveOperator(), $action)];
    }

    /**
     * Where a record is actually written. Nullable for the same reason as
     * $systemLog: the command tests construct commands by hand, without a
     * container. See RecordWriterInterface for why the write path sits behind
     * an interface rather than in these commands.
     */
    protected ?RecordWriterInterface $recordWriter = null;

    #[Required]
    public function setRecordWriter(RecordWriterInterface $recordWriter): void
    {
        $this->recordWriter = $recordWriter;
    }

    /**
     * The writer, or a message that says what to do about its absence.
     *
     * In the container it is always injected. It can only be missing in a test
     * that builds a command by hand — and no test reached this point when the
     * writer was introduced, because they all exercise error paths that return
     * before anything is written. The first one that does exercise a successful
     * write should get this sentence rather than a null-pointer.
     */
    protected function writer(): RecordWriterInterface
    {
        return $this->recordWriter ?? throw new \LogicException(\sprintf(
            'No RecordWriter on %s. The container injects it via #[Required]; a test '
            . 'constructing this command by hand must call setRecordWriter() — with a real '
            . 'ModelWriter if it asserts a successful write, since a bare mock returns null '
            . 'and would read as "record not found".',
            static::class,
        ));
    }

    protected function configure(): void
    {
        $this->addOption(
            'set', null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Field=value pairs, e.g. --set email=foo@bar.com',
            []
        );
        $this->addOption(
            'operator', null,
            InputOption::VALUE_REQUIRED,
            'Acting user identifier for the audit log. Backend integrations pass the '
            . 'Contao username here so audit/version rows attribute changes correctly. '
            . 'When omitted, falls back to $_SERVER[USER] (CLI operator).',
            ''
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input  = $input;
        $this->output = $output;

        $fields = $this->parseSetOptions($input->getOption('set'));

        return $this->guarded($output, fn (): int => $this->doExecute($fields));
    }

    abstract protected function doExecute(array $fields): int;

    public function parseSetOptions(array $setOptions): array
    {
        $result = [];
        foreach ($setOptions as $pair) {
            $pos = strpos($pair, '=');
            if ($pos === false) {
                continue;
            }
            $key = substr($pair, 0, $pos);
            $val = substr($pair, $pos + 1);
            if ($key !== '') {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    /**
     * Called by every create command right after its record is saved, which makes
     * it the one place all creates pass once the record exists — so the cache is
     * invalidated here too. CacheInvalidationAfterCreateTest keeps "save first"
     * true for every *CreateCommand.
     */
    /** @var array<string, int> versions found under a created record's ID, per `table.id` */
    private array $earlierVersions = [];

    /** Why a generated alias did not come from Contao's callback, see resolveAlias(). */
    private ?string $aliasWarning = null;

    /**
     * Pages whose URL may collide with a written page, reported as Contao's back end does.
     *
     * @var list<array{id: int, title: string, alias: string, path: string}>
     */
    protected array $routeConflicts = [];

    /**
     * The route conflicts collected so far, and forget them.
     *
     * @return list<array{id: int, title: string, alias: string, path: string}>
     */
    protected function takeRouteConflicts(): array
    {
        $conflicts            = $this->routeConflicts;
        $this->routeConflicts = [];

        return $conflicts;
    }

    /**
     * @param bool $created true right after creating the record: its first version is
     *                      marked, so versions of an earlier record with the same ID can
     *                      be told apart (v0.16.0, RecordIdReuseTest)
     */
    protected function createVersion(string $table, int $id, bool $created = false): void
    {
        if ($created) {
            $earlier = $this->versionManager->createInitialVersion($table, $id, $this->resolveOperator());

            if ($earlier > 0) {
                $this->earlierVersions[$table . '.' . $id] = $earlier;
            }
        } else {
            $this->versionManager->createVersion($table, $id, $this->resolveOperator());
        }

        $this->cacheTags?->recordChanged($table, $id);
    }

    /**
     * Operator identifier for audit/version rows. Backend bundle passes the
     * Contao backend user via `--operator`; CLI invocations fall back to the
     * shell user — useful for distinguishing "ssh into prod" vs. "agent action".
     */
    protected function resolveOperator(): string
    {
        $explicit = (string) ($this->input->getOption('operator') ?? '');
        if ('' !== $explicit) {
            return $explicit;
        }
        return (string) ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent');
    }

    /**
     * Resolves the Contao user ID matching the current operator name. Used as
     * `$record->author` on create so audit/byline reflect the actual editor,
     * not the admin (id=1) the framework defaults to.
     *
     * Falls back to id=1 when the operator is empty (CLI), unknown to Contao,
     * or the user lookup fails — keeps existing CLI behaviour intact.
     */
    /**
     * Value for Contao's boolean columns (published, invisible, …).
     *
     * They are `tinyint NOT NULL`, so an empty string is not a falsy value but an
     * invalid one: a lax server coerces it to 0, a server running STRICT_ALL_TABLES
     * throws "Incorrect integer value: ''". That is how `page:publish <id> unpublish`
     * came to fail with a DriverException while publishing worked.
     */
    public function booleanFlag(bool $on): string
    {
        return $on ? '1' : '0';
    }

    protected function resolveAuthorId(): int
    {
        return $this->resolveOperatorUserId(1);
    }

    /**
     * Contao user ID behind the current operator name, or $fallback when the
     * operator is empty, unknown to Contao, or the lookup fails.
     *
     * The fallback differs by purpose: `author` on create wants id=1 so a byline
     * is never empty, while `tl_undo.pid` wants 0 — that column means "the backend
     * user who deleted this", and a plain CLI deletion had no backend user.
     */
    protected function resolveOperatorUserId(int $fallback): int
    {
        $name = $this->resolveOperator();
        if ('' === $name) {
            return $fallback;
        }
        if (!class_exists(\Contao\UserModel::class)) {
            return $fallback;
        }
        $user = \Contao\UserModel::findOneBy('username', $name);
        return $user ? (int) $user->id : $fallback;
    }

    /**
     * Convert string UUID(s) into Contao's binary storage form for every
     * `fileTree` DCA field of the given table (e.g. tl_content.singleSRC,
     * tl_news.singleSRC, multiSRC/orderSRC).
     *
     * Contao references files by *binary* UUID; a raw UUID string written to
     * such a column never resolves to a file, so the image/enclosure stays
     * empty. This mirrors what the backend FileTree widget does on save, so a
     * record created/updated via CLI behaves identically to a backend one.
     * Values that are not a canonical UUID string are left untouched
     * (defensive against re-runs, empty values or already-binary input).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    /**
     * Input types that store a serialized list without carrying `eval.multiple`.
     *
     * `cud` is the create/update/delete permission table on `tl_user_group`,
     * `chmod` the owner/group/world table on `tl_page` and `tl_files`. Both are
     * widgets whose whole purpose is a list, so Contao never sets the flag.
     */
    private const ARRAY_INPUT_TYPES = ['cud', 'chmod'];

    /**
     * Every conversion the write path owes a table, behind one name.
     *
     * 🎯 The point is not brevity — it is that forgetting becomes hard.
     * Both halves arrived one command at a time: `convertFileTreeFields()` in
     * v0.2.15, `convertMultipleFields()` in v0.2.18. Both times the create
     * commands were missed, because each one had to remember to call them.
     * Measured on 2026-08-31: of eleven create commands that accept `--set`,
     * four converted fileTree values and exactly one converted multi-value
     * fields. The rest wrote a raw string into a serialized column and reported
     * success — the same silent shape as the bugs the two helpers fixed.
     *
     * CreateCommandConversionTest asserts that every create command taking
     * `--set` calls this, so the next one cannot quietly skip it.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function convertFields(string $table, array $fields, ?int $excludeId = null): array
    {
        // First, and for every command: an inputUnit field becomes its
        // {value, unit} pair here. Until v0.11.0 only content create and layout
        // create did this, and every other create wrote the raw string — see
        // InputUnitCentralConversionTest. Before refuseUnknownFields() because
        // the companion "<field>_unit" key is not a column and is consumed here.
        // The checks below split the pair again (inputUnitHalves()).
        $fields = $this->convertInputUnitFields($table, $fields);
        // Fields read as arrays can be written back as JSON (v0.16.0).
        $fields = $this->convertJsonStructuredFields($table, $fields);

        $this->refuseUnknownFields($table, $fields);
        // Both on the raw input, before the conversions below turn UUIDs binary
        // and lists into serialized strings — a rule about what the caller
        // typed has to see what the caller typed.
        $this->refuseInvalidValues($table, $fields);
        // Also on the raw input, and for the same reason: `convertEmptyValues()`
        // below turns '' into false, which would hide the difference between a
        // deliberately empty value and a typo.
        $this->refuseInvalidBooleans($table, $fields);
        // Also on the raw input: a multi-value field arrives as "a,b,c" and is
        // serialized further down, so this is the last point at which the parts
        // are still readable as the caller typed them.
        $this->refuseInvalidOptions($table, $fields);
        // Also on the raw input: convertFileTreeFields() drops what is not a
        // UUID, so a half-wrong list would pass afterwards (Nr. 69).
        $this->refuseInvalidFileTreeValues($table, $fields);
        $this->refuseTakenUniqueValues($table, $fields, $excludeId);
        // A custom template has to exist. Needs the element type, so the stored
        // record is merged in on an update. See CustomTemplateRefusalTest.
        if (\array_key_exists('customTpl', $fields)) {
            $this->refuseUnknownTemplates($table, $fields, $this->storedRow($table, $excludeId));
        }

        $fields = $this->convertFileTreeFields($table, $fields);
        $fields = $this->convertOptionFields($table, $fields);
        $fields = $this->convertMultipleFields($table, $fields);

        // After the conversions, not before: `optionWizard` has a short form
        // that only becomes an array above. What is still not one now, no
        // conversion could make one — that is the silent case.
        $this->refuseUnstructuredValues($table, $fields);

        // Last on purpose: the three above leave empty values alone, and this
        // one turns them into something that is no longer the empty string.
        return $this->convertEmptyValues($table, $fields);
    }

    /**
     * Refuse a `--set` field that is not a column of this table.
     *
     * `--set gibtesnicht=1` answered `{"status":"ok","updated":["gibtesnicht"]}`.
     * Nothing was written: `Model::save()` filters `arrModified` against
     * `Database::getFieldNames()` and drops what is not a column. But
     * `ModelWriter::update()` reported back the field names it was *given*, so
     * a typo read as a successful change.
     *
     * 🎯 **That is the failure this project keeps hunting: a silent no-op that
     * reports success.** The same shape as the bulk run of 2026-08-29 (174 IDs,
     * one record changed, "0 failed") and the pipx no-op of v0.4.3. A wrong
     * answer that looks like an answer is worse than an error, because nobody
     * goes looking.
     *
     * Refusing rather than reporting truthfully, because there is precedent for
     * it on the read side: `contao:record:list` validates `--fields`, `--filter`
     * and `--order` against the DCA and refuses anything else. Guessing a column
     * name is safe there precisely because it fails loudly. Writing should not
     * be the looser of the two.
     *
     * ⚠️ **Checked against the real columns, not the DCA.** They are not the
     * same set — a DCA can declare a field without an `sql` key, and an extension
     * can leave a column behind. (Until 2026-09-16 this named `tl_layout.rows` as
     * the example; the column exists in 5.3 and 5.7. What the undo work of
     * 2026-08-31 ran into was `rows` being a reserved word in MySQL 8.) What decides
     * whether a write lands is the column list, so that is what this asks, via
     * the very function `Model::save()` uses to make that decision.
     *
     * A failure to read the column list is not treated as a failed check: if the
     * database cannot answer, the write will fail on its own terms and say so.
     * Blocking here on a lookup error would turn an infrastructure problem into
     * a confusing validation message.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \InvalidArgumentException when a field is not a column of $table
     */
    protected function refuseUnknownFields(string $table, array $fields): void
    {
        if ([] === $fields) {
            return;
        }

        $columns = $this->tableColumns($table);
        if ([] === $columns) {
            return;
        }

        $unknown = array_values(array_diff(array_keys($fields), $columns));
        if ([] === $unknown) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Not a column of %s: %s. Nothing was written. Contao drops unknown fields on '
            . 'save, so passing one used to report success while changing nothing — check '
            . 'the spelling against `contao:dca:schema %s`.',
            $table,
            implode(', ', $unknown),
            $table,
        ));
    }

    /**
     * The complete field set for a create, options included, rules applied once.
     *
     * 🎯 **Every create command used to take its own options past the rules.**
     * `--set` pairs went through `convertFields()`; `--name`, `--title`,
     * `--email` and the computed values beside them were assigned straight onto
     * the model afterwards. So `theme create --name "Contao Official Demo"`
     * happily made a second theme under a name `eval.unique` forbids —
     * demonstrated on c5 on 2026-09-01, half an hour after the `unique` check
     * was added and believed to cover creates.
     *
     * Three commands had noticed and each written their own check
     * (`UserGroupCreate`, `MemberGroupCreate`, `FormCreate`). That is the shape
     * this bundle keeps learning to distrust: a rule every command has to
     * remember, which most of them did not.
     *
     * So a create hands over what it produced itself, and the whole record —
     * options, computed values, `--set` — is judged and converted together.
     *
     * ⚠️ **`--set` still wins on a collision**, which is what the direct
     * assignments did before: they ran first, and the `--set` loop overwrote
     * them. Preserved deliberately; whether `--set name=x` *should* override
     * `--name y` is a separate question with its own answer.
     *
     * @param array<string, mixed> $own the command's own options and computed values
     * @param array<string, mixed> $set the parsed `--set` pairs
     *
     * @return array<string, mixed>
     */
    /**
     * The sorting value for a record created at the end of its siblings.
     *
     * Until v0.13.0 pages, articles, content elements and FAQs were created with
     * `sorting = 0` — only the form field and image size item commands computed a
     * value, each with a private copy. See SortingOnCreateTest.
     *
     * Siblings are the same `pid`, and for a `dynamicPtable` table the same
     * `ptable`. Hand the result to preparedFields() as the command's own value, so
     * a `--set sorting=` of the caller still wins.
     *
     * `$table` is always a literal of the calling command, never caller input.
     */
    protected function nextSorting(string $table, int $pid, ?string $ptable = null): int
    {
        return $this->sortingAfter($this->maxSorting($table, $pid, '' === $ptable ? null : $ptable));
    }

    /**
     * The highest sorting value among the siblings, or null when there are none.
     *
     * Only the lookup — the rule stays in nextSorting(). A command that already has
     * a Doctrine connection overrides this, which keeps it testable with a mock.
     */
    protected function maxSorting(string $table, int $pid, ?string $ptable): ?int
    {
        $sql    = 'SELECT MAX(sorting) AS max_sorting FROM ' . $table . ' WHERE pid=?';
        $params = [$pid];

        if (null !== $ptable) {
            $sql     .= ' AND ptable=?';
            $params[] = $ptable;
        }

        $row = Database::getInstance()->prepare($sql)->execute(...$params)->fetchAssoc();
        $max = \is_array($row) ? ($row['max_sorting'] ?? null) : null;

        return null === $max ? null : (int) $max;
    }

    protected function sortingAfter(?int $max): int
    {
        return Sorting::after($max);
    }

    protected function preparedFields(string $table, array $own, array $set, ?int $excludeId = null): array
    {
        return $this->convertFields($table, array_merge($own, $set), $excludeId);
    }

    /**
     * The alias to store: the one given, or one generated and made unique.
     *
     * Contao splits these two cases and answers them differently
     * (`tl_news::generateAlias` and its twins):
     *
     *  - **given** — a duplicate is an error. The caller chose it, so silently
     *    changing it would store something they did not ask for.
     *  - **generated** — the slug service is handed an `$aliasExists` callback
     *    and appends until it fits. Refusing here would make this bundle
     *    stricter than the back end for a value the caller never typed.
     *
     * Only the numeric refusal is applied to both: Contao cannot tell `123`
     * apart from a record ID, so it is never a legal alias.
     *
     * The duplicate case for a *given* alias is not handled here — it falls to
     * the `unique` check in `convertFields()`, which is where every other
     * uniqueness answer comes from.
     *
     * @throws \InvalidArgumentException on a purely numeric alias
     */
    protected function resolveAlias(string $table, string $given, string $from, string $field = 'alias', array $record = []): string
    {
        if ('' !== $given) {
            $this->refuseNumericAlias($given);

            return $given;
        }

        // Generated: by the field's own Contao callback where there is one, which
        // honours the language and `validAliasCharacters` of the page the record
        // belongs to. Until v0.16.0 every create made `über-uns` where Contao makes
        // `ueber-uns` (review 2026-09-16, ContaoAliasTest). Pages are generated after
        // the write by PageUrlGuard; the callback needs the saved page.
        if ([] !== $record && 'tl_page' !== $table) {
            try {
                $alias = ContaoAlias::generate($table, $record, $field);
            } catch (\Throwable $e) {
                // A callback that cannot run here (a missing parent, a service it
                // needs) leaves the alias to the fallback below rather than failing
                // the create — the fallback is what every create did until v0.16.0.
                // Not silently: in the first live test a missing import landed here
                // and looked exactly like success (2026-09-16).
                $alias = null;
                $this->aliasWarning = \sprintf("Contao's alias callback for %s failed (%s: %s); the alias was generated without it.", $table, $e::class, $e->getMessage());

                if (isset($this->logger)) {
                    $this->logger->warning('contao-ai-core-bundle alias fallback', ['table' => $table, 'error' => $e->getMessage()]);
                }
            }

            if (null !== $alias && '' !== $alias) {
                return $alias;
            }
        }

        $base = StringUtil::generateAlias($from);
        $this->refuseNumericAlias($base);

        // ⚠️ Only where the DCA actually says unique. `tl_page.alias` and
        // `tl_article.alias` carry no `eval.unique` — a page alias may repeat
        // across roots, and Contao scopes that check by root and domain in its
        // own `save_callback`. Suffixing them here would rename a page for a
        // clash that is not one.
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'][$field]['eval']['unique'])) {
            return $base;
        }

        if ($this->uniqueValueIsFree($table, $field, $base, null)) {
            return $base;
        }

        // Contao's slug service appends until the callback says free; the shape
        // of the suffix is ours, the behaviour is Contao's.
        for ($i = 2; $i < 100; ++$i) {
            $candidate = $base . '-' . $i;

            if ($this->uniqueValueIsFree($table, $field, $candidate, null)) {
                return $candidate;
            }
        }

        return $base . '-' . substr(md5(uniqid('', true)), 0, 8);
    }

    /**
     * @throws \InvalidArgumentException when the alias is a plain number
     */
    private function refuseNumericAlias(string $alias): void
    {
        if (preg_match('/^[1-9]\d*$/', $alias)) {
            throw new \InvalidArgumentException(\sprintf(
                'A purely numeric alias ("%s") is not allowed — Contao cannot tell it apart '
                . 'from a record ID.',
                $alias,
            ));
        }
    }

    /**
     * Refuse a value that `eval.unique` says is already taken.
     *
     * `tl_user.username`, `tl_member.email`, `tl_theme.name`, the four aliases —
     * thirteen fields across the bundled DCAs carry `eval.unique`, and for most
     * of them there is **no unique index behind it**. The rule lives in the DCA
     * alone, so a write path that goes around `DC_Table` drops it with the rest.
     * Two back end users called the same name is exactly the kind of thing
     * nobody notices until the day it matters which one someone logged in as.
     *
     * 🎯 **Contao's own check, not a rebuilt one.**
     * `Database::isUniqueValue($table, $field, $value, $id)` is public and takes
     * the id to exclude — `DC_Table::save()` calls it with the record being
     * edited, which is what makes "save without changing the name" work. That
     * parameter is the whole reason this was create-only until now: without it,
     * every update of a unique field would refuse itself.
     *
     * ⚠️ **The note that held this back was wrong, and measuring settled it.**
     * It said a DCA-wide check "would also reject renames that pass today
     * (`tl_page.alias`)". `tl_page.alias` has **no** `eval.unique` — page
     * aliases may repeat across roots and are checked by a `save_callback`
     * instead. It was never in scope, so the objection had nothing behind it.
     *
     * An empty value is skipped, the same way `DC_Table::save()` skips it: the
     * check runs only for `(string) $varValue !== ''`. Several unique fields are
     * optional, and refusing the second empty alias would be absurd.
     *
     * @param array<string, mixed> $fields
     * @param int|null             $excludeId the record being updated, or null when creating
     *
     * @throws \InvalidArgumentException listing every value that is already taken
     */
    protected function refuseTakenUniqueValues(string $table, array $fields, ?int $excludeId = null): void
    {
        if ([] === $fields) {
            return;
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        $taken = [];

        foreach ($fields as $name => $value) {
            if (empty($GLOBALS['TL_DCA'][$table]['fields'][$name]['eval']['unique'])) {
                continue;
            }
            if (!\is_scalar($value) || '' === (string) $value) {
                continue;
            }

            if (!$this->uniqueValueIsFree($table, (string) $name, $value, $excludeId)) {
                $taken[] = \sprintf('%s=%s', $name, (string) $value);
            }
        }

        if ([] === $taken) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Already taken in %s: %s. Nothing was written. The DCA marks these fields '
            . 'unique, and the back end refuses a duplicate the same way.',
            $table,
            implode('; ', $taken),
        ));
    }

    /**
     * Whether $value is still free in $table.$field, ignoring record $excludeId.
     *
     * `Database::isUniqueValue()` is the function `DC_Table::save()` calls, so
     * asking it is asking the thing that decides the answer in the back end
     * rather than a second implementation that could drift from it.
     *
     * Its own method so a test can answer for it, and so a database that cannot
     * be reached says "free" rather than "taken": no answer is not the same as
     * a duplicate, and the write then fails on its own terms — same reasoning
     * as `tableColumns()`.
     */
    protected function uniqueValueIsFree(string $table, string $field, mixed $value, ?int $excludeId): bool
    {
        try {
            return (bool) \Contao\Database::getInstance()->isUniqueValue($table, $field, $value, $excludeId);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * DCA `rgxp` names and the `Validator` method Contao checks them with.
     *
     * Lifted from the switch in `Widget::validator()`, one line per case, in
     * the same order. Not invented here: `rgxp` is a keyword, not a regular
     * expression, and Contao resolves each keyword to exactly one of these.
     *
     * ⚠️ **`date`, `time` and `datim` are deliberately absent.** Their `rgxp`
     * describes the *widget input* — a date in the configured display format —
     * and `DC_Table::save()` converts that to a timestamp before it reaches the
     * column. `--set` writes the stored form, so `--set date=1756598400` is
     * correct and `Validator::isDatim('1756598400')` would refuse it. Checking
     * them here would reject the right value and accept the wrong one.
     *
     * `friendly` and `emails` are handled separately below; both need the value
     * split before an address check.
     */
    private const RGXP_VALIDATORS = [
        'digit'       => 'isNumeric',
        'natural'     => 'isNatural',
        'alpha'       => 'isAlphabetic',
        'alnum'       => 'isAlphanumeric',
        'extnd'       => 'isExtendedAlphanumeric',
        'email'       => 'isEmail',
        'url'         => 'isUrl',
        'alias'       => 'isAlias',
        'folderalias' => 'isFolderAlias',
        'phone'       => 'isPhone',
        'prcnt'       => 'isPercent',
        'locale'      => 'isLocale',
        'language'    => 'isLanguage',
        'fieldname'   => 'isFieldName',
    ];

    /**
     * Refuse a `--set` value that Contao's own widget would refuse.
     *
     * `eval.rgxp` is the rule behind "that is not an e-mail address" in the back
     * end. `DC_Table` enforces it by running every field through its widget;
     * this write path goes around `DC_Table`, so until now `--set sender=keine`
     * landed in the column unchallenged. 145 `rgxp` declarations across 28 DCA
     * files in a stock Contao 5.7.12 — the largest DCA rule this bundle was
     * still ignoring.
     *
     * 🎯 **Same shape as `unique` and the empty-value mapping: a rule that lives
     * in the DCA and is lost together with `DC_Table` when you write past it.**
     * And the same answer — ask Contao. Every keyword resolves to one
     * `Validator` method, so this is Contao's rule executed, not a second
     * opinion about what an e-mail address is.
     *
     * Three things are skipped rather than refused, each for a reason:
     *
     *  - **an empty value** — `Widget::validator()` returns before the switch
     *    when the input is `''`, so an empty optional field is not a format
     *    error. Composes with `convertEmptyValues()`, which maps it afterwards.
     *  - **a non-scalar value** — arrays reach the widget as arrays and take a
     *    different path there; nothing in `--set` produces one.
     *  - **an unknown `rgxp`** — extensions add their own through the
     *    `addCustomRegexp` hook. Refusing what this list does not know would
     *    break a field whose rule simply lives elsewhere.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \InvalidArgumentException listing every value that fails its rule
     */
    protected function refuseInvalidValues(string $table, array $fields): void
    {
        if ([] === $fields) {
            return;
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        $offenders = [];

        foreach ($fields as $name => $value) {
            $def  = $GLOBALS['TL_DCA'][$table]['fields'][$name] ?? [];
            $rgxp = $def['eval']['rgxp'] ?? null;

            if (!\is_string($rgxp) || '' === $rgxp || !\is_scalar($value)) {
                continue;
            }

            // The widget decides which parts the rgxp applies to — never a
            // serialized whole or a comma list. See RgxpPartsTest.
            foreach ($this->rgxpParts($def, (string) $value) as $part) {
                if ('' !== $part && !$this->passesRgxp($rgxp, $part)) {
                    $offenders[] = \sprintf('%s=%s (expected: %s)', $name, $part, $rgxp);
                }
            }
        }

        if ([] === $offenders) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Rejected by the DCA rule for %s: %s. Nothing was written. These are Contao\'s '
            . 'own eval.rgxp rules — the same ones the back end applies to the field.',
            $table,
            implode('; ', $offenders),
        ));
    }

    private ?OptionsResolver $optionsResolver = null;

    #[Required]
    public function setOptionsResolver(OptionsResolver $optionsResolver): void
    {
        $this->optionsResolver = $optionsResolver;
    }

    /**
     * Refuse a `customTpl` that is not among the templates Contao offers for the record.
     *
     * 🟡 **Measured on 2026-09-16 on c5:** `--set customTpl=content_element/text/
     * gibtesnicht` was stored with `ok`; the element then silently renders its default.
     *
     * `options_callback` fields are not enforced in general (see refuseInvalidOptions()).
     * `customTpl` is the exception because Contao's `TemplateOptionsListener` needs only
     * the element type, which the record carries. An empty value clears the template.
     * When the options cannot be resolved, nothing is refused — the list is not guessed.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $record the stored row on an update, empty on a create
     *
     * @throws \InvalidArgumentException naming the valid templates
     */
    protected function refuseUnknownTemplates(string $table, array $fields, array $record): void
    {
        $value = $fields['customTpl'] ?? '';

        if (null === $this->optionsResolver || !\is_string($value) || '' === $value) {
            return;
        }

        $allowed = $this->optionsResolver->values($table, 'customTpl', array_merge($record, $fields), (int) ($record['id'] ?? 0));

        if (null === $allowed || \in_array($value, $allowed, true)) {
            return;
        }

        $variants = array_values(array_filter($allowed, static fn (string $t): bool => '' !== $t));

        throw new \InvalidArgumentException(\sprintf(
            'Not a template Contao offers for this record: customTpl=%s. Nothing was written. Available: %s',
            $value,
            // '' is Contao's default template — say so instead of "none".
            [] === $variants ? 'only the default template (customTpl=)' : implode(', ', $variants) . ', or the default (customTpl=)',
        ));
    }

    /**
     * The stored row of a record being updated, or an empty array for a create.
     *
     * @return array<string, mixed>
     */
    private function storedRow(string $table, ?int $id): array
    {
        if (null === $id) {
            return [];
        }

        $row = Database::getInstance()->prepare('SELECT * FROM ' . $table . ' WHERE id=?')->execute($id)->fetchAssoc();

        return \is_array($row) ? $row : [];
    }

    /**
     * The parts of a value that `eval.rgxp` applies to, as Contao's widget sees them.
     *
     * | widget | parts |
     * |---|---|
     * | `inputUnit` | the value (the unit goes against options) |
     * | `imageSize` | width `[0]` and height `[1]` (`[2]` is a size ID or mode) |
     * | `timePeriod` | the value (the unit goes against options) |
     * | `text` with `eval.multiple` | every entry, comma list or serialized |
     * | anything else | the value as a whole |
     *
     * Until v0.13.0 only `inputUnit` was split; an image size and the multi-entry
     * text fields could not be written in any form. See RgxpPartsTest.
     *
     * @param array<string, mixed> $def
     *
     * @return list<string>
     */
    private function rgxpParts(array $def, string $value): array
    {
        $type = $def['inputType'] ?? null;

        if ('inputUnit' === $type) {
            return [$this->inputUnitHalves($def, $value)['value'] ?? $value];
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        $isArray      = \is_array($unserialized);

        if ('imageSize' === $type) {
            return $isArray
                ? [self::scalarPart($unserialized[0] ?? ''), self::scalarPart($unserialized[1] ?? '')]
                : [$value];
        }

        if ('timePeriod' === $type) {
            return $isArray ? [self::scalarPart($unserialized['value'] ?? '')] : [$value];
        }

        if ('text' === $type && ($def['eval']['multiple'] ?? false)) {
            $entries = $isArray ? array_values($unserialized) : explode(',', $value);

            return array_map(static fn ($entry): string => trim(self::scalarPart($entry)), $entries);
        }

        return [$value];
    }

    private static function scalarPart(mixed $part): string
    {
        return \is_scalar($part) ? (string) $part : '';
    }

    /**
     * The two halves of an `inputUnit` value, or null for any other field.
     *
     * By the time the checks run, `convertInputUnitFields()` has already turned
     * the input into `serialize(['value' => …, 'unit' => …])`. A value that is
     * not (yet) serialized is the value half on its own, with no unit to check.
     *
     * @param array<string, mixed> $def
     *
     * @return array{value: string, unit: string}|null
     */
    private function inputUnitHalves(array $def, mixed $value): ?array
    {
        if ('inputUnit' !== ($def['inputType'] ?? null) || !\is_scalar($value)) {
            return null;
        }

        $pair = @unserialize((string) $value, ['allowed_classes' => false]);

        if (\is_array($pair) && \array_key_exists('value', $pair)) {
            return [
                'value' => \is_scalar($pair['value']) ? (string) $pair['value'] : '',
                'unit'  => \is_scalar($pair['unit'] ?? null) ? (string) $pair['unit'] : '',
            ];
        }

        return ['value' => (string) $value, 'unit' => ''];
    }

    /**
     * Whether one value satisfies one `rgxp` keyword.
     *
     * Unknown keywords pass: see the note on custom regexps above.
     */
    private function passesRgxp(string $rgxp, string $value): bool
    {
        // Contao decodes entities before these two, so the same input has to be
        // judged the same way here.
        if ('extnd' === $rgxp || 'phone' === $rgxp) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
        }

        // "Name <addr>" — Contao splits it and checks the address half.
        if ('friendly' === $rgxp) {
            [, $address] = StringUtil::splitFriendlyEmail($value);

            return Validator::isEmail($address);
        }

        // A comma-separated list; every entry has to hold up.
        if ('emails' === $rgxp) {
            foreach (StringUtil::trimsplit(',', $value) as $address) {
                if ('' !== $address && !Validator::isEmail($address)) {
                    return false;
                }
            }

            return true;
        }

        $method = self::RGXP_VALIDATORS[$rgxp] ?? null;

        return null === $method || Validator::$method($value);
    }

    /**
     * The strings a boolean column accepts. Everything else is refused.
     *
     * Not a taste decision — this is the set Contao itself produces for such a
     * column: `''` is what an unchecked checkbox submits, `'1'` what a checked
     * one and the back end's toggle write, and `'0'` what this class's own
     * `booleanFlag()` emits.
     *
     * ⚠️ **`'true'` and `'yes'` are deliberately absent.** Accepting them would
     * be inventing a vocabulary Contao does not have, and every caller would
     * then have to guess which words this bundle happens to know.
     *
     * They were never a working input either. Measured on 2026-09-05:
     * `--set published=true` exits 1 with a `DriverException` on Contao 5.7.13
     * and leaves the record untouched. Accepting it here would not restore old
     * behaviour — it would add new behaviour, on the one field where being
     * wrong publishes something.
     */
    private const BOOLEAN_INPUTS = ['', '0', '1'];

    /**
     * Refuse a value that is not in the field's declared option list.
     *
     * 🔴 **The gap, measured on 2026-09-11.** `--set consho_shop=999` wrote a
     * number with no record behind it and answered `{"status":"ok"}`. The back
     * end prevents that with a select list; the database does not, and this
     * write path goes around the widget. Same shape as `unique`, `eval.rgxp`
     * and the empty-value mapping — a rule that lives in the DCA and is lost
     * together with `DC_Table`.
     *
     * ## Only `options`. Not `foreignKey`, not `options_callback`.
     *
     * Of 1183 fields in a stock 5.7.13 with all five optional bundles, 279
     * declare an options source. The split decided the scope:
     *
     * | source | fields | enforced | why |
     * |---|---|---|---|
     * | `options` | 94 | **yes** | the list is in the DCA, complete and closed |
     * | `foreignKey` | 79 | no | see below |
     * | `options_callback` | 106 | no | needs a live `DataContainer` this path has not, and may answer differently per record |
     *
     * ⚠️ **`foreignKey` is deliberately not enforced, and the measurement is
     * why.** Of 55 scalar, checkable foreign-key fields on that installation,
     * one was broken: `tl_news.jumpTo` points at page 13 in all 27 rows that
     * set it, and page 13 does not exist. The field is declared `mandatory`.
     * Contao allowed the page to be deleted and cleans up nothing — so a
     * dangling reference is a state **Contao itself produces**. Refusing it on
     * write while the framework creates it on delete would make this CLI
     * stricter than Contao at a place where Contao has decided otherwise.
     *
     * `DcaSchemaCommand` reports `optionsTarget` (v0.8.1) so a caller who wants
     * the check can do it. That is the honest division: we enforce what the DCA
     * settles, and hand over what it merely describes.
     *
     * The same reasoning as unknown `rgxp` keywords, which pass rather than
     * fail: what an extension contributes is not ours to reject.
     *
     * ## Multi-value fields
     *
     * A field with `eval.multiple` arrives as `"a,b,c"` and is serialized by
     * `convertMultipleFields()` further down, so every part is checked
     * separately here — this is the last point at which they are still readable
     * as the caller typed them. A value that already arrives serialized is
     * checked member by member, the same way that converter recognises it.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \InvalidArgumentException listing every value that is not an option
     */
    protected function refuseInvalidOptions(string $table, array $fields): void
    {
        if ([] === $fields) {
            return;
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        $dca       = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $offenders = [];

        foreach ($fields as $name => $value) {
            $def     = $dca[$name] ?? null;
            $allowed = null === $def ? null : $this->optionValues($def);

            // No declared list — nothing to hold the value against.
            if (null === $allowed || [] === $allowed) {
                continue;
            }

            // An inputUnit field: the options list the units, so only the unit
            // half is held against them. The value half is free text or a
            // number and belongs to the rgxp check. See InputUnitValidationTest.
            if ('inputUnit' === ($def['inputType'] ?? null)) {
                $unit = $this->inputUnitHalves($def, $value)['unit'] ?? '';

                if ('' !== $unit && !\in_array($unit, $allowed, true)) {
                    $offenders[] = \sprintf('%s unit=%s (allowed: %s)', $name, $unit, implode(', ', \array_slice($allowed, 0, 12)));
                }

                continue;
            }

            foreach ($this->optionParts($def, $value) as $part) {
                // An empty value is cleared, not chosen; `convertEmptyValues()`
                // maps it afterwards, exactly as `DC_Table::save()` does.
                if ('' === $part || \in_array($part, $allowed, true)) {
                    continue;
                }

                $offenders[] = \sprintf(
                    '%s=%s (allowed: %s)',
                    $name,
                    $part,
                    implode(', ', \array_slice($allowed, 0, 12)) . (\count($allowed) > 12 ? ', …' : ''),
                );
            }
        }

        if ([] === $offenders) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Not an allowed value for %s: %s. Nothing was written. These are the '
            . 'options the DCA declares for the field — the same list the back '
            . 'end offers in its select.',
            $table,
            implode('; ', $offenders),
        ));
    }

    /**
     * The individual values behind one `--set`, as strings.
     *
     * One for a plain field; several for a multi-value one, which arrives
     * comma-separated or already serialized.
     *
     * @param array<string, mixed> $def
     *
     * @return list<string>
     */
    private function optionParts(array $def, mixed $value): array
    {
        if (\is_bool($value) || null === $value) {
            return [];
        }

        if (\is_array($value)) {
            return array_map(static fn ($v): string => (string) $v, array_values($value));
        }

        if (!\is_scalar($value)) {
            return [];
        }

        $value = (string) $value;

        $storesArray = ($def['eval']['multiple'] ?? false)
            || \in_array($def['inputType'] ?? null, self::ARRAY_INPUT_TYPES, true);

        if (!$storesArray) {
            return [$value];
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        if (\is_array($unserialized)) {
            return array_map(static fn ($v): string => (string) $v, array_values($unserialized));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $part): bool => '' !== $part,
        ));
    }

    /**
     * Refuse a non-boolean value for a boolean column.
     *
     * 🔴 **Measured on 2026-09-05 against 5.7.13 and 6.0.0**, same command, same
     * input:
     *
     * | `--set` | Contao 5.7.13 | Contao 6.0.0 |
     * |---|---|---|
     * | `sorting=keinezahl` | exit 1, `DriverException` | exit 1, `InvalidArgumentException` naming the field |
     * | `published=vielleicht` | exit 1, `DriverException` | **exit 0, `published=true`** |
     * | `published=true` | exit 1, `DriverException` | **exit 0, `published=true`** |
     * | `published=1` / `=0` / `=` | exit 0, correct | exit 0, correct |
     *
     * Contao 6 casts a value into the column's declared type instead of letting
     * the database refuse it. For integers that is an improvement: the error
     * arrives earlier and names the field. For booleans there is no error left
     * — `(bool) 'vielleicht'` is `true`, so a typo publishes the page.
     *
     * ⚠️ **This is not a defect in Contao.** In the back end the value comes
     * from a checkbox widget, which cannot produce `vielleicht`; the cast never
     * sees anything but `''` or `'1'`. The write path here goes around
     * `DC_Table` and therefore around the widget — the same shape as `unique`,
     * `eval.rgxp` and the empty-value mapping, and it gets the same answer:
     * enforce what the DCA says instead of writing past it.
     *
     * 🎯 **Refused rather than coerced.** Accepting `yes`/`on`/`ja` would invent
     * a rule Contao does not have, and every caller would then have to guess
     * which words this bundle happens to know. A refusal that names the
     * accepted values is something an agent can act on; a coercion is something
     * it never learns about.
     *
     * The signal is `sql.type === 'boolean'`, which is how Contao recognises
     * such a column itself (`Widget::getEmptyValueByFieldType()`), and it is
     * identical in 5.7 and 6.0 — 115 columns in the stock DCA of both. A `sql`
     * given as a string is left alone: that form declares a text column, which
     * has no cast to be surprised by.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \InvalidArgumentException listing every offending field
     */
    protected function refuseInvalidBooleans(string $table, array $fields): void
    {
        if ([] === $fields) {
            return;
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        $offenders = [];

        foreach ($fields as $name => $value) {
            $sql = $GLOBALS['TL_DCA'][$table]['fields'][$name]['sql'] ?? null;

            if (!\is_array($sql) || 'boolean' !== ($sql['type'] ?? null)) {
                continue;
            }

            // Real booleans and the integers 0/1 are already the value the
            // column holds; only strings can carry a typo.
            if (\is_bool($value) || 0 === $value || 1 === $value) {
                continue;
            }

            if (\is_string($value) && \in_array($value, self::BOOLEAN_INPUTS, true)) {
                continue;
            }

            $offenders[] = \sprintf('%s=%s', $name, \is_scalar($value) ? (string) $value : \gettype($value));
        }

        if ([] === $offenders) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Not a boolean value for %s: %s. Nothing was written. Pass 1 or 0 '
            . '(an empty value is also accepted and means 0). Any other text is '
            . 'cast to true on Contao 6 and refused by the database on Contao 5 — '
            . 'neither does what the value says.',
            $table,
            implode('; ', $offenders),
        ));
    }

    /**
     * The real column names of $table, or [] when they cannot be determined.
     *
     * `Database::getFieldNames()` is the very function `Model::save()` filters
     * against, so asking it is asking the thing that decides the outcome rather
     * than a second source that could disagree.
     *
     * Its own method so a test can answer for it: the static
     * `Database::getInstance()` needs a booted framework, and a rule about
     * column names should be testable without one.
     *
     * @return list<string>
     */
    protected function tableColumns(string $table): array
    {
        try {
            return array_values(\Contao\Database::getInstance()->getFieldNames($table));
        } catch (\Throwable) {
            // No answer is not the same as "no such column": if the database
            // cannot be reached the write fails on its own terms and says so.
            return [];
        }
    }

    /**
     * An empty `--set field=` becomes the empty value that column can hold.
     *
     * `--set teaser=` clears a text column and always worked. `--set addFile=`
     * did not: `tl_newsletter.addFile` is `['type' => 'boolean']`, and MySQL in
     * strict mode answers an empty string with *Incorrect integer value*. The
     * DBAL exception escaped uncaught — a stack trace and exit 255 out of a
     * command whose entire contract is a JSON result. Same syntax, two
     * outcomes, decided by a column type the caller cannot see.
     *
     * 🎯 **Contao already answers this, and it publishes the answer.**
     * `Widget::getEmptyValueByFieldType()` takes the DCA `sql` definition and
     * returns the empty value for that column: `null` where the column is
     * nullable, `0` for the integer family, `false` for `boolean`, `''`
     * otherwise. `DC_Table::save()` calls it for exactly this purpose.
     *
     * So this is not a rule of ours. Refusing the empty value instead — the
     * first instinct — would have made the CLI stricter than the back end at a
     * place where Contao has a considered answer and hands it over as a public
     * static method. No `protected` to reach around, no shape to guess.
     *
     * Because it returns `''` for string columns, it needs no special-casing:
     * running every empty value through it leaves text fields exactly as they
     * were.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function convertEmptyValues(string $table, array $fields): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        foreach ($fields as $name => $value) {
            if ('' !== $value) {
                continue;
            }

            $sql = $GLOBALS['TL_DCA'][$table]['fields'][$name]['sql'] ?? null;
            if (null === $sql) {
                continue;
            }

            $fields[$name] = Widget::getEmptyValueByFieldType($sql);
        }

        return $fields;
    }

    /**
     * Input types whose widget stores a serialized array, independent of
     * `eval.multiple`. Counted in the DCA files of a stock 5.7.13 with all
     * optional bundles; `inputUnit`, `cud` and `chmod` have their own
     * conversion and are listed as a net under it.
     */
    private const STRUCTURED_INPUT_TYPES = StructuredFields::INPUT_TYPES;

    /**
     * Turn a JSON array or object into Contao's serialized form, for a field that
     * stores one (v0.16.0).
     *
     * Reads answer these fields as arrays since the same version, so what was read can
     * be written back: `--set 'modules=[{"mod":"66","col":"header","enable":"1"}]'`.
     * Values are stored as strings, as the back end's widgets submit them. An already
     * serialized value is left alone. A value that starts with `[` or `{` but is not
     * valid JSON is refused (v0.26.0) — until then it was left alone, and a list field
     * split it at the commas further down. `inputUnit` has its own JSON form in
     * convertInputUnitFields(). See StructuredFieldRoundTripTest.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function convertJsonStructuredFields(string $table, array $fields): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $invalid = [];

        foreach ($fields as $key => $value) {
            $def = $dca[$key] ?? null;

            if (!\is_string($value) || !\is_array($def) || 'inputUnit' === ($def['inputType'] ?? null) || !StructuredFields::storesArray($def)) {
                continue;
            }

            $trimmed = ltrim($value);
            if ('' === $trimmed || ('[' !== $trimmed[0] && '{' !== $trimmed[0])) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (\is_array($decoded)) {
                // A page list as integers, like the comma form (castListValues()) and
                // Contao's picker. Until v0.16.0 JSON stored strings (review 2026-09-16).
                $fields[$key] = 'pageTree' === ($def['inputType'] ?? null) && array_is_list($decoded)
                    ? serialize(array_map('intval', $decoded))
                    : serialize(self::stringLeaves($decoded));

                continue;
            }

            $invalid[] = \sprintf('%s=%s', $key, mb_strimwidth($value, 0, 60, '…'));
        }

        // 🔴 Measured on 2026-09-18 (agent test with Codex, Windows PowerShell 5.1):
        // the shell dropped the double quotes of `--set 'listitems=["Eins","Zwei","Drei"]'`,
        // so `[Eins,Zwei,Drei]` arrived. Left alone, the comma conversion further down
        // split it into `['[Eins', 'Zwei', 'Drei]']` and the command answered ok —
        // the brackets became part of the content. A value that starts like JSON in a
        // field that stores an array is JSON or a mistake; it is never meant as a comma
        // list that happens to begin with a bracket. See StructuredFieldRoundTripTest.
        if ([] !== $invalid) {
            throw new \InvalidArgumentException(\sprintf(
                'Not valid JSON for %s: %s. Nothing was written. The value starts like a JSON '
                . 'list or object but does not parse — typically because the shell dropped the '
                . 'double quotes (Windows PowerShell 5.1 does that for native programs). Pass valid '
                . 'JSON, e.g. listitems=["a","b"]; the comma form a,b works only when no item '
                . 'starts with a bracket.',
                $table,
                implode(', ', $invalid),
            ));
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function stringLeaves(array $data): array
    {
        foreach ($data as $k => $v) {
            $data[$k] = \is_array($v) ? self::stringLeaves($v) : (\is_scalar($v) ? (string) $v : $v);
        }

        return $data;
    }

    /**
     * Refuse a value that is not an array for a field whose widget stores one.
     *
     * 🔴 **Measured on 2026-09-16 on c5 (5.7.13, v0.11.0):** `layout update 25
     * --set modules=66` answered `{"status":"ok"}` and stored the string `66`.
     * The layout lost its module list and a page on it rendered nothing — with
     * no error anywhere. The back end cannot produce that: a `moduleWizard`
     * only ever submits an array.
     *
     * Runs after the conversions, so a short form that becomes an array
     * (`options="red|green"`) passes, and only what nothing could convert is
     * refused. `eval.multiple` is not covered on purpose: with `eval.csv` such
     * a field stores a comma-separated string. See StructuredValueRefusalTest.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \InvalidArgumentException naming every field whose value is not an array
     */
    protected function refuseUnstructuredValues(string $table, array $fields): void
    {
        if ([] === $fields) {
            return;
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        $offenders = [];

        foreach ($fields as $name => $value) {
            $type = $GLOBALS['TL_DCA'][$table]['fields'][$name]['inputType'] ?? null;

            if (!\in_array($type, self::STRUCTURED_INPUT_TYPES, true) || !\is_string($value) || '' === $value) {
                continue;
            }

            if (!\is_array(@unserialize($value, ['allowed_classes' => false]))) {
                $offenders[] = \sprintf('%s (%s)', $name, $type);
            }
        }

        if ([] === $offenders) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Not a structured value for %s: %s. Nothing was written. These widgets store '
            . 'a serialized PHP array — pass Contao\'s own form, e.g. modules='
            . 'a:1:{i:0;a:3:{s:3:"mod";s:2:"66";s:3:"col";s:6:"header";s:6:"enable";s:1:"1";}}.',
            $table,
            implode(', ', $offenders),
        ));
    }

    /**
     * `optionWizard` fields: a list of `{value, label}` pairs.
     *
     * `tl_form_field.options` holds
     * `a:2:{i:0;a:2:{s:5:"value";s:3:"mrs";s:5:"label";s:4:"Mrs.";}…}` — a
     * nested structure no one is going to type into a `--set`.
     *
     * 🎯 **This is the one place where inventing a short form is the right
     * call, and the reason is that the field is mandatory.** `select`, `radio`
     * and `checkbox` cannot be created without options, so "pass Contao's
     * serialized form" — the answer given for `tl_settings.allowedAttributes`,
     * which is optional and rarely touched — would mean those three types stay
     * uncreatable. The gap this command exists to close would still be there.
     *
     *   --set options="mrs=Mrs.|mr=Mr."     value and label
     *   --set options="red|green|blue"      label doubles as the value
     *
     * A value already in Contao's format is left alone, so re-running is a
     * no-op and a caller who does have the serialized form still works.
     *
     * Key order follows what is actually on disk (value, then label). Contao
     * reads these by key, so the order is cosmetic — but a record this bundle
     * writes should look like one the back end wrote.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function convertOptionFields(string $table, array $fields): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        foreach ($fields as $key => $value) {
            if (!\is_string($value) || '' === $value) {
                continue;
            }
            if ('optionWizard' !== ($dca[$key]['inputType'] ?? null)) {
                continue;
            }
            if (\is_array(@unserialize($value, ['allowed_classes' => false]))) {
                continue; // already in Contao's format
            }

            $options = [];
            foreach (explode('|', $value) as $part) {
                $part = trim($part);
                if ('' === $part) {
                    continue;
                }

                $pos = strpos($part, '=');
                if (false === $pos) {
                    $options[] = ['value' => $part, 'label' => $part];
                    continue;
                }

                $options[] = [
                    'value' => trim(substr($part, 0, $pos)),
                    'label' => trim(substr($part, $pos + 1)),
                ];
            }

            if ([] !== $options) {
                $fields[$key] = serialize($options);
            }
        }

        return $fields;
    }

    /**
     * Whether a DCA `unique` value is already taken.
     *
     * `tl_user_group.name` and `tl_member_group.name` carry `eval.unique`, and
     * DC_Table refuses a duplicate in the back end. There is no unique index
     * behind it — the rule lives in the DCA alone — so a write path that goes
     * around DC_Table drops the rule with it. Two permission groups called
     * "Editors" is exactly the kind of thing nobody notices until the day it
     * matters which one a user is in.
     *
     * ⚠️ **Superseded by `refuseTakenUniqueValues()` (2026-09-01), which runs
     * inside `convertFields()` and therefore covers create and update alike.**
     * Kept because a few create commands still call it directly; the checks
     * agree, so the duplicate is harmless — but new code should not add one.
     *
     * The note that used to stand here said an update-side check "would also
     * start rejecting renames that succeed today (`tl_page.alias` among them)".
     * That was wrong: `tl_page.alias` carries no `eval.unique` at all — page
     * aliases may repeat across roots and are checked by a `save_callback`.
     * Counting the DCAs settled it, and the objection had nothing behind it.
     */
    protected function valueTaken(string $modelClass, string $field, string $value): bool
    {
        return $modelClass::countBy($field, $value) > 0;
    }

    /**
     * Mandatory fields Contao would insist on for this record, and no others.
     *
     * 🎯 **A field is only mandatory where Contao actually shows it.** `DC_Table`
     * validates the fields of the *active* palette, so `eval.mandatory` is a
     * conditional rule, not a property of the field. `tl_module` has 113 fields
     * and twelve mandatory ones, yet 21 of its 45 types need nothing but a
     * name — reading the flags alone would produce a command nobody can call.
     *
     * Two levels, because Contao has two:
     *
     *  - **palette** — `palettes[$paletteKey]`, the fields shown for this record
     *    (for `tl_module` the key is the module type, elsewhere `default`)
     *  - **subpalette** — `subpalettes[$selector]`, shown only once the selector
     *    is switched on. `tl_news_archive.groups` is mandatory, but only for a
     *    protected archive; demanding it always would refuse every public one.
     *
     * This started as two separate checks — the palette rule in
     * `ModuleCreateCommand`, the subpalette rule in `MemberGroupCreateCommand` —
     * with a note that a third caller would be the moment to unify them rather
     * than guess at the shape early. The three parent tables were that caller.
     *
     * @param string       $paletteKey key in `palettes`, e.g. `default` or a module type
     * @param array<string, mixed> $fields  the `--set` fields
     * @param list<string> $handled    fields the command takes as its own options
     *
     * @return list<string>
     */
    public function missingMandatoryFields(string $table, string $paletteKey, array $fields, array $handled = []): array
    {
        $dca     = $GLOBALS['TL_DCA'][$table] ?? [];
        $visible = [];

        $palette = $dca['palettes'][$paletteKey] ?? null;
        if (\is_string($palette) && '' !== $palette) {
            $visible = array_map('trim', preg_split('/[;,]/', $palette) ?: []);
        }

        foreach ($dca['subpalettes'] ?? [] as $selector => $subPalette) {
            if (!\is_string($subPalette)) {
                continue;
            }

            // Only what the caller actually switched on. An untouched selector
            // means the subpalette is closed and its fields are not in play.
            $on = (string) ($fields[(string) $selector] ?? '');
            if ('' === $on || '0' === $on) {
                continue;
            }

            $visible = [...$visible, ...array_map('trim', explode(',', $subPalette))];
        }

        $missing = [];
        foreach ($dca['fields'] ?? [] as $field => $definition) {
            $field = (string) $field;

            if (\in_array($field, $handled, true) || empty($definition['eval']['mandatory'])) {
                continue;
            }
            if (!\in_array($field, $visible, true)) {
                continue;
            }
            if ('' === (string) ($fields[$field] ?? '')) {
                $missing[] = $field;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Store a multi-value field the way Contao stores it: a serialized array.
     *
     * A DCA field with `eval.multiple` holds `a:1:{i:0;s:1:"1";}`, not `1`.
     * Passing `--set news_archives=1` wrote the bare string, and
     * `StringUtil::deserialize()` hands a non-array straight back — so the
     * module had an archive that was not a list of archives, and read as empty
     * wherever Contao iterated it. Nothing failed; it just did nothing.
     *
     * Comma-separated input becomes a list: `--set news_archives=1,3` stores
     * both. A value that already unserializes to an array is left alone, so a
     * caller passing Contao's own format still works and re-running is a no-op.
     * Empty values are left alone too — an unset multiple is `''` in the
     * database, not an empty array, and inventing one would be a change nobody
     * asked for.
     *
     * `fileTree` fields are skipped: convertFileTreeFields() already serializes
     * their `multiple` form, and it has to convert the UUIDs on the way.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function convertMultipleFields(string $table, array $fields): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        foreach ($fields as $key => $value) {
            if (!\is_string($value) || '' === $value) {
                continue;
            }
            $def = $dca[$key] ?? null;
            if (null === $def || 'fileTree' === ($def['inputType'] ?? null)) {
                continue;
            }

            // `eval.multiple` covers the checkbox-style fields. It does not cover
            // the permission widgets: `tl_user_group.cud` and `tl_page.chmod`
            // store a flat serialized list exactly like a multiple field, but
            // carry no `multiple` flag — the widget itself is the list. Verified
            // against live data on c5: cud is a:60:{i:0;s:21:"tl_form_field::create";…},
            // chmod is a:9:{i:0;s:2:"u1";…}.
            $storesArray = ($def['eval']['multiple'] ?? false)
                || \in_array($def['inputType'] ?? null, self::ARRAY_INPUT_TYPES, true);

            if (!$storesArray) {
                continue;
            }
            if (\is_array(@unserialize($value, ['allowed_classes' => false]))) {
                continue; // already in Contao's format
            }

            $parts = array_values(array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $part): bool => '' !== $part,
            ));

            if ([] !== $parts) {
                $fields[$key] = serialize($this->castListValues($def, $parts));
            }
        }

        return $fields;
    }

    /**
     * The element type Contao's own widget would have stored.
     *
     * Almost every list is a list of strings, but `pageTree` is not: its
     * validator runs `array_map('\intval', …)`, so a page mount reaches the
     * database as `a:1:{i:0;i:1;}` and not `a:1:{i:0;s:1:"1";}`.
     *
     * Measured on 2026-08-31 rather than assumed. Of every widget in
     * core-bundle, exactly two cast to int at all — `PageTree` and `Picker` —
     * and `Picker` only does so on its single-value branch: a comma-separated
     * Picker list stays strings. So the rule is `pageTree` plus `multiple`,
     * and nothing else.
     *
     * Both forms read the same today, because every consumer compares loosely
     * (`array_intersect` in BackendAccessVoter, `in_array(…, false)` for
     * groups). Matching the format anyway is the point of this bundle: a record
     * it writes should be indistinguishable from one the back end wrote.
     *
     * @param array<string, mixed> $definition DCA field definition
     * @param list<string>         $parts
     *
     * @return list<int>|list<string>
     */
    private function castListValues(array $definition, array $parts): array
    {
        if ('pageTree' !== ($definition['inputType'] ?? null)) {
            return $parts;
        }

        return array_map('intval', $parts);
    }

    protected function convertFileTreeFields(string $table, array $fields): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        foreach ($fields as $key => $value) {
            if (!\is_string($value) || ($dca[$key]['inputType'] ?? null) !== 'fileTree') {
                continue;
            }
            $multiple = (bool) ($dca[$key]['eval']['multiple'] ?? false);

            // An empty JSON list clears the field, as '' does. Left raw, the text
            // "[]" would be stored and answered ok (review before v0.24.0).
            if ($multiple && [] === json_decode(trim($value), true)) {
                $fields[$key] = '';
                continue;
            }

            $fields[$key] = $this->uuidStringsToBin($value, $multiple);
        }

        return $fields;
    }

    /**
     * @param string $value    single UUID, or comma-separated list for multiple
     * @param bool   $multiple whether the DCA field stores an array of UUIDs
     */
    private function uuidStringsToBin(string $value, bool $multiple): string
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        if (!$multiple) {
            $single = trim($value);
            return preg_match($pattern, $single) ? StringUtil::uuidToBin($single) : $value;
        }

        $bins = [];
        foreach (self::fileTreeParts($value) as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match($pattern, $part)) {
                $bins[] = StringUtil::uuidToBin($part);
            }
        }

        // Nothing looked like a UUID → leave the raw value untouched rather
        // than clobbering the field with an empty serialized array.
        return $bins === [] ? $value : serialize($bins);
    }

    /**
     * The parts of a multiple fileTree value: a JSON list, as reads answer it
     * since v0.2.15, or the comma form.
     *
     * @return list<string>
     */
    private static function fileTreeParts(string $value): array
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            if (\is_array($decoded) && array_is_list($decoded)) {
                return array_map(static fn (mixed $v): string => \is_scalar($v) ? trim((string) $v) : '', $decoded);
            }
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * Refuse a fileTree value that would not resolve to a file.
     *
     * 🔴 **Measured on 2026-09-17 on web.werk.wien (Contao 5.7.13, v0.23.0),
     * Nr. 69:** `layout update 2 --set 'external=["7cb2d18f-…"]'` answered
     * `{"status":"ok"}` and stored the JSON text. The layout never linked its
     * stylesheet. The JSON list was what `layout read` answered for the field,
     * so the read value could not be written back, and nothing said so.
     *
     * Checked on the raw input, like the other refusals: the conversion drops
     * a part that is not a UUID, so afterwards a half-wrong list looks valid.
     * Accepted: a UUID (single), a list of UUIDs as JSON or comma form, the
     * binary or serialized form already stored. See FileTreeValueRefusalTest.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \InvalidArgumentException naming every field and the parts that are not UUIDs
     */
    protected function refuseInvalidFileTreeValues(string $table, array $fields): void
    {
        if ([] === $fields) {
            return;
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }

        $pattern   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $offenders = [];

        foreach ($fields as $name => $value) {
            $def = $GLOBALS['TL_DCA'][$table]['fields'][$name] ?? null;

            if (!\is_array($def) || 'fileTree' !== ($def['inputType'] ?? null) || !\is_string($value) || '' === trim($value)) {
                continue;
            }

            if (!($def['eval']['multiple'] ?? false)) {
                if (!preg_match($pattern, trim($value)) && !Validator::isBinaryUuid($value)) {
                    $offenders[] = \sprintf('%s ("%s")', $name, $value);
                }

                continue;
            }

            if (\is_array(@unserialize($value, ['allowed_classes' => false]))) {
                continue;
            }

            $bad = array_filter(self::fileTreeParts($value), static fn (string $p): bool => !preg_match($pattern, $p));

            if ([] !== $bad) {
                $offenders[] = \sprintf('%s ("%s")', $name, implode('", "', $bad));
            }
        }

        if ([] === $offenders) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Not a file UUID for %s: %s. Nothing was written. fileTree fields take UUIDs — '
            . 'one, or for a list a JSON array ["<uuid>", …] or "<uuid>,<uuid>". '
            . 'contao:file:read answers a file\'s UUID.',
            $table,
            implode(', ', $offenders),
        ));
    }

    /**
     * Serialize Contao "inputUnit" fields (e.g. tl_content.headline,
     * tl_news.headline) into their canonical {value, unit} storage form.
     *
     * Contao stores these as a serialized {value, unit} pair. This writes value
     * first, as the column's SQL default does (a:2:{s:5:"value";...;s:4:"unit";...}).
     * Records saved in the back end may hold unit first — 139 of 144 headlines on
     * web.werk.wien (2026-09-17, Nr. 56) — and Contao reads both by key, so the
     * order carries no meaning. The unit is resolved in
     * this order:
     *   1. a companion "<field>_unit" key in the --set payload
     *   2. a JSON object value {"unit":"h1","value":"..."} given as the field
     *   3. the unit of the record's current value (update path, via $record)
     *   4. $defaultUnit, or — when a command passes none, as convertFields() does
     *      for every write since v0.12.0 — the unit in the field's SQL default,
     *      else the first option
     * A value that already is a {value, unit} pair is left alone.
     * A unit the caller gives (1 or 2) that the field's DCA options do not list
     * is refused (since v0.11.0). A stored or default unit that is not listed
     * falls back to the default, as before. Companion "<field>_unit" keys are consumed so
     * they never reach the model as unknown columns.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    /**
     * The unit an `inputUnit` field's SQL default declares, or '' if none.
     *
     * Contao keeps it there — `tl_module.headline` declares
     * `default 'a:2:{s:5:"value";s:0:"";s:4:"unit";s:2:"h2";}'` — and writes it
     * when the back end creates a record. Both forms are read: the SQL string of
     * Contao 5 and the `['default' => …]` array. '' lets the caller fall through
     * to the field's first option.
     *
     * @param array<string, mixed> $def
     */
    private function sqlDefaultUnit(array $def): string
    {
        $sql     = $def['sql'] ?? null;
        $default = null;

        if (\is_array($sql) && \is_string($sql['default'] ?? null)) {
            $default = $sql['default'];
        } elseif (\is_string($sql) && preg_match("/\\bdefault\\s+'((?:[^'\\\\]|\\\\.|'')*)'/i", $sql, $m)) {
            $default = stripslashes(str_replace("''", "'", $m[1]));
        }

        if (null === $default || '' === $default) {
            return '';
        }

        $pair = @unserialize($default, ['allowed_classes' => false]);

        return \is_array($pair) && \is_string($pair['unit'] ?? null) ? $pair['unit'] : '';
    }

    protected function convertInputUnitFields(string $table, array $fields, ?string $defaultUnit = null, ?object $record = null): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        // A unit without its value: `--set headline_unit=h1` changes the level of
        // the stored headline. Until v0.16.0 the companion key was consumed below
        // and the command answered "ok" with nothing written (review 2026-09-16).
        // The stored value is carried over as the JSON form, so the steps below
        // treat it like any caller-given value — the unit check included.
        foreach ($fields as $key => $value) {
            if (!\is_string($key) || !str_ends_with($key, '_unit') || !\is_string($value) || '' === $value) {
                continue;
            }
            $base = substr($key, 0, -5);
            if (($dca[$base]['inputType'] ?? null) !== 'inputUnit' || \array_key_exists($base, $fields)) {
                continue;
            }

            $stored = null === $record ? null : ($record->$base ?? null);
            $prev   = \is_string($stored) && '' !== $stored ? @unserialize($stored, ['allowed_classes' => false]) : null;

            $fields[$base] = json_encode(['value' => \is_array($prev) ? (string) ($prev['value'] ?? '') : '']);
        }

        foreach ($fields as $key => $value) {
            if (!\is_string($key) || str_ends_with($key, '_unit')) {
                continue; // companion keys are handled alongside their base field
            }
            if (($dca[$key]['inputType'] ?? null) !== 'inputUnit' || !\is_string($value)) {
                continue;
            }

            // Already a pair — converted by the command before convertFields(),
            // or given in Contao's own form. Wrapping it again would store the
            // serialized string as the value. See InputUnitCentralConversionTest.
            $pair = @unserialize($value, ['allowed_classes' => false]);
            if (\is_array($pair) && \array_key_exists('value', $pair)) {
                continue;
            }

            // The allowed units as Contao reads them: the values of a list, the keys
            // of an associative array — the same as refuseInvalidOptions(). Until
            // v0.16.0 the raw array was compared, which refused `['h1' => 'Heading 1']`.
            $options = $this->optionValues($dca[$key]) ?? [];
            $val     = $value;
            $unit    = null;
            $fieldDefault = $defaultUnit ?? $this->sqlDefaultUnit($dca[$key]);

            // (2) the value itself is a {"unit","value"} JSON object.
            // NOTE: json_decode() + is_array() instead of json_validate(), which
            // only exists from PHP 8.3 — this bundle supports PHP ^8.2. If the
            // minimum PHP requirement is ever raised to 8.3+, this can be
            // simplified with json_validate() before decoding.
            $decoded = json_decode($value, true);
            if (\is_array($decoded) && \array_key_exists('value', $decoded)) {
                $val  = (string) $decoded['value'];
                $unit = isset($decoded['unit']) ? (string) $decoded['unit'] : null;
            }

            // (1) explicit companion "<field>_unit" wins
            $unitKey = $key . '_unit';
            if (\array_key_exists($unitKey, $fields) && \is_string($fields[$unitKey]) && '' !== $fields[$unitKey]) {
                $unit = $fields[$unitKey];
            }

            // A unit the caller named — companion key or JSON — is refused when
            // the DCA does not list it. Until v0.10.0 it was silently replaced
            // by the default and answered "ok" (measured on c5, 2026-09-16).
            // Only the caller's own unit: a stored or default unit that is not
            // listed still falls back below, so old data cannot make a record
            // unwritable.
            if (null !== $unit && '' !== $unit && !empty($options) && !\in_array($unit, $options, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Not an allowed unit for %s: %s=%s (allowed: %s). Nothing was written.',
                    $table,
                    $unitKey,
                    $unit,
                    implode(', ', array_map('strval', $options)),
                ));
            }

            // (3) preserve the record's existing unit on update
            if (null === $unit && null !== $record) {
                $current = $record->$key ?? null;
                if (\is_string($current) && '' !== $current) {
                    $prev = @unserialize($current, ['allowed_classes' => false]);
                    if (\is_array($prev) && isset($prev['unit']) && \is_string($prev['unit'])) {
                        $unit = $prev['unit'];
                    }
                }
            }

            // (4) fall back to the default
            if (null === $unit || '' === $unit) {
                $unit = $fieldDefault;
            }

            // validate against the DCA options; invalid → default (or first option)
            if (!empty($options) && !\in_array($unit, $options, true)) {
                $unit = \in_array($fieldDefault, $options, true) ? $fieldDefault : (string) $options[0];
            }

            $fields[$key] = serialize(['value' => $val, 'unit' => $unit]);
        }

        // Drop consumed / orphan "<field>_unit" companion keys for inputUnit
        // fields so they are never written as (non-existent) columns.
        foreach (array_keys($fields) as $k) {
            if (\is_string($k) && str_ends_with($k, '_unit')
                && ($dca[substr($k, 0, -5)]['inputType'] ?? null) === 'inputUnit'
            ) {
                unset($fields[$k]);
            }
        }

        return $fields;
    }

    /**
     * Which `tl_log` action a command's entries carry. GENERAL matches what
     * Contao writes for record changes; the file commands override this with
     * ContaoContext::FILES so the back end's action filter keeps working.
     */
    protected function systemLogAction(): string
    {
        return ContaoContext::GENERAL;
    }

    /**
     * The line shown in the back end's log list.
     *
     * Deliberately the raw payload rather than prose: the same success data the
     * caller already gets, so the log never says something different from what
     * the command returned. Prose would mean 30+ hand-written strings, each one
     * a chance to drift from the record it describes.
     */
    protected function systemLogText(array $data): string
    {
        return trim(sprintf(
            '%s %s',
            (string) $this->getName(),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ));
    }

    /**
     * Write the audit rows for one changed record, without answering the caller.
     *
     * Split out from outputSuccess() for the bulk path: `--ids` touches N records
     * and must leave the same per-record trail a single update leaves — that trail
     * is the whole reason writes go through the console rather than SQL — while
     * the caller gets one summary instead of N lines.
     */
    protected function logSuccess(array $data): void
    {
        $this->logger->info('contao-ai-core-bundle audit', [
            'command'  => $this->getName(),
            'user'     => $this->resolveOperator(),
            'payload'  => $data,
        ]);
        // Above goes to var/logs (and in a Managed Edition often nowhere at all);
        // this is the entry an editor can actually find, under System > System log.
        $this->systemLog?->write(
            $this->systemLogText($data),
            (string) $this->getName(),
            $this->resolveOperator(),
            $this->systemLogAction(),
        );
    }

    protected function outputSuccess(array $data): void
    {
        // The new record's ID was used before, and that record's versions are still
        // there. Said here because `version restore` refuses them and a caller should
        // not have to find out that way.
        if ([] !== $this->earlierVersions) {
            $data['earlierVersions'] = $this->earlierVersions;
        }

        if (null !== $this->aliasWarning) {
            $data['aliasWarning'] = $this->aliasWarning;
        }

        // A hint, not a refusal — what Contao's back end shows under the alias field
        // (Nr. 48 of the ConpAI 1.0 acceptance test, 2026-09-17).
        if ([] !== $this->routeConflicts) {
            $data['routeConflicts'] = $this->routeConflicts;
        }

        $this->logSuccess($data);
        $this->output->writeln(json_encode(['status' => 'ok'] + $this->withCacheReport($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    protected function outputError(string $message, int $code = 1): int
    {
        $this->output->writeln(json_encode([
            'status'  => 'error',
            'message' => $message,
            'code'    => $code,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return Command::FAILURE;
    }
}
