<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

/**
 * Fields whose value no read command hands out: Contao's own rule for the back end's
 * detail view (`DC_Table::show()`, 5.3 to 6.0) — `inputType => 'password'`,
 * `eval.doNotShow` or `eval.hideInput`.
 *
 * Up to v1.8.0 `record list tl_user --fields ai_api_key,password,secret` answered with
 * the API keys of the chat in plain text, the password hashes and the two-factor
 * secrets; `version:read` showed the same in every stored version of a user. Whoever
 * runs the CLI can read the database anyway, but the CLI is mostly driven by agents,
 * and what it answers ends up in their transcripts.
 *
 * Read from the DCA, so a field an extension declares the same way is covered: the
 * backend bundle's `ai_api_key` carries `hideInput` and `doNotShow`. Measured in
 * Contao 5.3.51: `tl_user` and `tl_member` — `password`, `session`, `secret`,
 * `backupCodes`, `trustedTokenVersion`, on `tl_user` also `lastLogin`; `tl_undo` —
 * `data`, `preview`.
 *
 * The field stays in the answer with {@see MASK} in place of a set value, so a caller
 * still sees that a key is set; an empty value stays empty.
 */
final class SensitiveFields
{
    public const MASK = '********';

    /**
     * @param array<string, mixed> $fields `$GLOBALS['TL_DCA'][$table]['fields']`
     *
     * @return list<string>
     */
    public static function of(array $fields): array
    {
        $names = [];

        foreach ($fields as $name => $definition) {
            if (\is_array($definition) && self::isSensitive($definition)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function isSensitive(array $definition): bool
    {
        $eval = \is_array($definition['eval'] ?? null) ? $definition['eval'] : [];

        return 'password' === ($definition['inputType'] ?? null)
            || (bool) ($eval['doNotShow'] ?? false)
            || (bool) ($eval['hideInput'] ?? false);
    }

    /**
     * $row with every sensitive field that holds a value replaced by {@see MASK}.
     *
     * Field names compared case-insensitively: MySQL column names are, and a row read
     * with `--fields AI_API_KEY` carries that spelling.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fields `$GLOBALS['TL_DCA'][$table]['fields']`
     *
     * @return array<string, mixed>
     */
    public static function mask(array $row, array $fields): array
    {
        $sensitive = array_map('strtolower', self::of($fields));

        if ([] === $sensitive) {
            return $row;
        }

        foreach ($row as $key => $value) {
            if (\in_array(strtolower((string) $key), $sensitive, true) && null !== $value && '' !== $value) {
                $row[$key] = self::MASK;
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function contains(string $column, array $fields): bool
    {
        return \in_array(strtolower($column), array_map('strtolower', self::of($fields)), true);
    }
}
