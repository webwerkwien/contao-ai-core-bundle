<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\StringUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

abstract class AbstractWriteCommand extends Command
{
    protected InputInterface $input;
    protected OutputInterface $output;
    protected VersionManager $versionManager;
    protected LoggerInterface $logger;

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
        return $this->doExecute($fields);
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
    protected function resolveAuthorId(): int
    {
        $name = $this->resolveOperator();
        if ('' === $name) {
            return 1;
        }
        if (!class_exists(\Contao\UserModel::class)) {
            return 1;
        }
        $user = \Contao\UserModel::findOneBy('username', $name);
        return $user ? (int) $user->id : 1;
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

    protected function outputSuccess(array $data): void
    {
        $this->logger->info('contao-ai-core-bundle audit', [
            'command'  => $this->getName(),
            'user'     => $this->resolveOperator(),
            'payload'  => $data,
        ]);
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
