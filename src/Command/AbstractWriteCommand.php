<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
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
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\RecordWriterInterface;

abstract class AbstractWriteCommand extends Command
{
    use JsonErrorBoundary;

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

    protected function createVersion(string $table, int $id): void
    {
        $this->versionManager->createVersion($table, $id, $this->resolveOperator());
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
        $this->refuseUnknownFields($table, $fields);
        // Both on the raw input, before the conversions below turn UUIDs binary
        // and lists into serialized strings — a rule about what the caller
        // typed has to see what the caller typed.
        $this->refuseInvalidValues($table, $fields);
        $this->refuseTakenUniqueValues($table, $fields, $excludeId);

        $fields = $this->convertFileTreeFields($table, $fields);
        $fields = $this->convertOptionFields($table, $fields);
        $fields = $this->convertMultipleFields($table, $fields);

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
     * same set — `tl_layout.rows` is declared in the DCA and does not exist in
     * the database, which the undo work of 2026-08-31 ran into. What decides
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
     * demonstrated on c5 on 2026-09-01, the day after the `unique` check was
     * added and believed to cover creates.
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
    protected function resolveAlias(string $table, string $given, string $from, string $field = 'alias'): string
    {
        if ('' !== $given) {
            $this->refuseNumericAlias($given);

            return $given;
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
            $rgxp = $GLOBALS['TL_DCA'][$table]['fields'][$name]['eval']['rgxp'] ?? null;

            if (!\is_string($rgxp) || '' === $rgxp || !\is_scalar($value) || '' === (string) $value) {
                continue;
            }

            if (!$this->passesRgxp($rgxp, (string) $value)) {
                $offenders[] = \sprintf('%s=%s (expected: %s)', $name, (string) $value, $rgxp);
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
        foreach (explode(',', $value) as $part) {
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
     * Serialize Contao "inputUnit" fields (e.g. tl_content.headline,
     * tl_news.headline) into their canonical {value, unit} storage form.
     *
     * Contao stores these as serialize(['value' => ..., 'unit' => ...]) — note
     * the key order (value first), matching the backend and the column's SQL
     * default (a:2:{s:5:"value";...;s:4:"unit";...}). The unit is resolved in
     * this order:
     *   1. a companion "<field>_unit" key in the --set payload
     *   2. a JSON object value {"unit":"h1","value":"..."} given as the field
     *   3. the unit of the record's current value (update path, via $record)
     *   4. $defaultUnit
     * The unit is validated against the field's DCA options; an invalid unit
     * falls back to the default. Companion "<field>_unit" keys are consumed so
     * they never reach the model as unknown columns.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function convertInputUnitFields(string $table, array $fields, string $defaultUnit = 'h2', ?object $record = null): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        foreach ($fields as $key => $value) {
            if (!\is_string($key) || str_ends_with($key, '_unit')) {
                continue; // companion keys are handled alongside their base field
            }
            if (($dca[$key]['inputType'] ?? null) !== 'inputUnit' || !\is_string($value)) {
                continue;
            }

            $options = $dca[$key]['options'] ?? [];
            $val     = $value;
            $unit    = null;

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
                $unit = $defaultUnit;
            }

            // validate against the DCA options; invalid → default (or first option)
            if (!empty($options) && !\in_array($unit, $options, true)) {
                $unit = \in_array($defaultUnit, $options, true) ? $defaultUnit : (string) $options[0];
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
        $this->logSuccess($data);
        $this->output->writeln(json_encode(['status' => 'ok'] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
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
