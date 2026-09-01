<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Contract;

use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;

/**
 * Read a command's declared contract without instantiating anything.
 *
 * `ReflectionAttribute::getArguments()` returns the raw values whether or not
 * the attribute class can be loaded; only `newInstance()` needs it. Reading
 * rather than instantiating is therefore not a shortcut but the whole point:
 * an extension can declare against this bundle **without depending on it**.
 *
 * ## Problems are reported, never dropped
 *
 * A malformed declaration comes back under `problems` and the field is left
 * out. Silently ignoring it would produce a contract that looks complete and
 * is not — the failure this project keeps meeting: an answer that reads like
 * an answer, so nobody looks further.
 *
 * A declaration with problems is still returned. Half a contract that says
 * which half is missing beats no contract at all.
 */
final class ContractReader
{
    /**
     * Positional arguments are refused rather than guessed at.
     *
     * `#[AiContract(true, ['tl_x'])]` is valid PHP and unreadable here: the
     * raw arguments arrive as `[0 => true, 1 => [...]]` and mapping them back
     * onto parameter names would mean hard-coding an order that the attribute
     * class is free to change. Named arguments are the only form.
     */
    private const HINT_NAMED = 'Use named arguments: #[AiContract(writes: true, tables: [...])]';

    /** @var array<string, string> field => expected type */
    private const FIELDS = [
        'writes'                => 'bool',
        'tables'                => 'string-list',
        'trace'                 => 'string-list',
        'traceWhen'             => 'trace-when',
        'irreversible'          => 'string',
        'repeatable'            => 'bool',
        'optionValues'          => 'string-list-map',
        'answerShape'           => 'string-list',
        'genericPathUnsuitable' => 'string-map',
    ];

    /**
     * Fields whose constructor default is null, where an explicit null is a
     * statement rather than a mistake.
     *
     * `irreversible: null` says *there is no irreversible effect* — the
     * attribute's own docblock says so, and it is the default. The validator
     * nevertheless answered *"must be a non-empty string, got null"*: a
     * complaint about a correct statement, whose only workaround was to leave
     * the field out and say the same thing less clearly. Reported from the
     * first real declaration written against this attribute (2026-09-01).
     *
     * The field is dropped rather than carried: silence and "explicitly
     * nothing" have to reach a reader the same way, or `repeatable: null` shows
     * up looking like a claim.
     */
    private const NULLABLE = ['traceWhen', 'irreversible', 'repeatable'];

    /** Contao's own trail tables. Anything else in `trace` is a typo or a misunderstanding. */
    private const KNOWN_TRACES = ['tl_version', 'tl_undo', 'tl_log'];

    private const TRACE_WHEN = ['before', 'on-success'];

    /**
     * The contract declared on $class, or null when there is none.
     *
     * @return array{fields: array<string, mixed>, problems: list<string>}|null
     */
    public static function read(string $class): ?array
    {
        if (!class_exists($class)) {
            return null;
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(AiContract::class);

        if ([] === $attributes) {
            return null;
        }

        $problems = [];

        if (\count($attributes) > 1) {
            $problems[] = \sprintf(
                '%d AiContract attributes on %s; only the first is read.',
                \count($attributes),
                $class,
            );
        }

        $raw    = $attributes[0]->getArguments();
        $fields = [];

        foreach ($raw as $name => $value) {
            if (\is_int($name)) {
                $problems[] = \sprintf('Positional argument #%d ignored. %s', $name, self::HINT_NAMED);
                continue;
            }

            if (!isset(self::FIELDS[$name])) {
                $problems[] = \sprintf(
                    'Unknown field "%s" ignored. Known fields: %s',
                    $name,
                    implode(', ', array_keys(self::FIELDS)),
                );
                continue;
            }

            if (null === $value && \in_array($name, self::NULLABLE, true)) {
                continue;
            }

            $problem = self::validate($name, self::FIELDS[$name], $value);

            if (null !== $problem) {
                $problems[] = $problem;
                continue;
            }

            $fields[$name] = $value;
        }

        // Not a type error, so the checks above cannot see it: a command that
        // says it writes and names no trace is exactly the case the `ext run`
        // warning describes, and staying quiet about it here would hide the
        // one thing a caller most needs to know.
        if (($fields['writes'] ?? false) && [] === ($fields['trace'] ?? [])) {
            $problems[] = 'writes: true but no trace declared — a caller cannot tell whether that '
                .'means "leaves nothing behind" or "not stated". Declare trace: [] explicitly if '
                .'the command genuinely leaves no trail.';
        }

        if (isset($fields['trace']) && [] !== $fields['trace'] && !isset($fields['traceWhen'])) {
            $problems[] = 'trace declared without traceWhen — "before" and "on-success" are different '
                .'promises, and the difference is the whole value of the field.';
        }

        return ['fields' => $fields, 'problems' => $problems];
    }

    /**
     * @return string|null the problem, or null when the value is acceptable
     */
    private static function validate(string $name, string $type, mixed $value): ?string
    {
        switch ($type) {
            case 'bool':
                return \is_bool($value) ? null : self::wrongType($name, 'a boolean', $value);

            case 'string':
                return \is_string($value) && '' !== trim($value)
                    ? null
                    : self::wrongType($name, 'a non-empty string', $value);

            case 'trace-when':
                if (!\is_string($value) || !\in_array($value, self::TRACE_WHEN, true)) {
                    return \sprintf(
                        'traceWhen must be one of %s, got %s.',
                        implode(' or ', array_map(static fn ($v) => '"'.$v.'"', self::TRACE_WHEN)),
                        self::describe($value),
                    );
                }

                return null;

            case 'string-list':
                if (!self::isStringList($value)) {
                    return self::wrongType($name, 'a list of strings', $value);
                }

                if ('trace' === $name) {
                    $unknown = array_values(array_diff($value, self::KNOWN_TRACES));

                    if ([] !== $unknown) {
                        return \sprintf(
                            'trace names %s, which Contao does not keep a trail in. Known: %s.',
                            implode(', ', $unknown),
                            implode(', ', self::KNOWN_TRACES),
                        );
                    }
                }

                return null;

            case 'string-map':
                foreach ((array) $value as $key => $entry) {
                    if (!\is_string($key) || !\is_string($entry) || '' === trim($entry)) {
                        return \sprintf(
                            '%s must map a table name to a non-empty reason; "%s" does not.',
                            $name,
                            \is_string($key) ? $key : self::describe($key),
                        );
                    }
                }

                return \is_array($value) ? null : self::wrongType($name, 'a map', $value);

            case 'string-list-map':
                if (!\is_array($value)) {
                    return self::wrongType($name, 'a map of option name to allowed values', $value);
                }

                foreach ($value as $key => $entry) {
                    if (!\is_string($key) || !self::isStringList($entry) || [] === $entry) {
                        return \sprintf(
                            '%s["%s"] must be a non-empty list of strings.',
                            $name,
                            \is_string($key) ? $key : self::describe($key),
                        );
                    }
                }

                return null;
        }

        return null;
    }

    private static function isStringList(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                return false;
            }
        }

        return true;
    }

    private static function wrongType(string $name, string $expected, mixed $value): string
    {
        return \sprintf('%s must be %s, got %s.', $name, $expected, self::describe($value));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            \is_bool($value)   => $value ? 'true' : 'false',
            null === $value    => 'null',
            \is_array($value)  => 'an array',
            \is_string($value) => '"'.$value.'"',
            default            => get_debug_type($value),
        };
    }
}
