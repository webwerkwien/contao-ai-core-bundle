<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\StringUtil;
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
    protected function convertFields(string $table, array $fields): array
    {
        $fields = $this->convertFileTreeFields($table, $fields);
        $fields = $this->convertOptionFields($table, $fields);
        $fields = $this->convertMultipleFields($table, $fields);

        // Last on purpose: the three above leave empty values alone, and this
        // one turns them into something that is no longer the empty string.
        return $this->convertEmptyValues($table, $fields);
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
     * 🟡 Create only. The generic update path does not check `unique` yet;
     * doing so DCA-wide would also start rejecting renames that succeed today
     * (`tl_page.alias` among them), which is a change of its own and wants its
     * own release. Noted in the project file.
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
