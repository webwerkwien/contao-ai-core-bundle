<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Webwerkwien\ContaoAiCoreBundle\Service\Dca\VirtualFields;

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
        return self::maskColumns($row, self::of($fields));
    }

    /**
     * The sensitive columns of a table as stored: its sensitive fields, and the JSON
     * column a sensitive virtual field lives in (Contao 5.7/6.0). A reader that does not
     * expand virtual fields — `record list` — would otherwise hand out the column whole,
     * the secret inside it (fixed in v1.8.1; Consho keeps bank data so).
     *
     * @return list<string>
     */
    public static function columns(string $table): array
    {
        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        if (!\is_array($fields)) {
            return [];
        }

        $names = self::of($fields);

        foreach (VirtualFields::map($table) as $virtual => $target) {
            if (\is_array($fields[$virtual] ?? null) && self::isSensitive($fields[$virtual])) {
                $names[] = $target;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * $row as stored, masked: sensitive fields by name, and inside the JSON column of a
     * virtual field only the keys of the sensitive ones — a non-sensitive neighbour in
     * the same column stays readable (before v1.8.1, masking the
     * whole column hid every field of a Consho payment method in `record list`, the only
     * read path such a table has). A column that does not decode to an object is masked
     * whole: what it holds is unknown.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function maskTable(string $table, array $row): array
    {
        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        if (!\is_array($fields)) {
            return $row;
        }

        $row = self::maskColumns($row, self::of($fields));

        $byTarget = [];
        foreach (VirtualFields::map($table) as $virtual => $target) {
            if (\is_array($fields[$virtual] ?? null) && self::isSensitive($fields[$virtual])) {
                $byTarget[strtolower($target)][] = $virtual;
            }
        }

        foreach ($row as $key => $value) {
            $virtuals = $byTarget[strtolower((string) $key)] ?? null;

            if (null === $virtuals || null === $value || '' === $value) {
                continue;
            }

            // A string as stored, or an array where a reader already decoded it.
            $decoded = \is_array($value) ? $value : (\is_string($value) ? json_decode($value, true) : null);

            if (!\is_array($decoded) || (array_is_list($decoded) && [] !== $decoded)) {
                $row[$key] = self::MASK;
                continue;
            }

            foreach ($virtuals as $virtual) {
                if (isset($decoded[$virtual]) && '' !== $decoded[$virtual] && [] !== $decoded[$virtual]) {
                    $decoded[$virtual] = self::MASK;
                }
            }

            $row[$key] = \is_array($value) ? $decoded : json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     *
     * @return array<string, mixed>
     */
    public static function maskColumns(array $row, array $columns): array
    {
        $sensitive = array_map('strtolower', $columns);

        if ([] === $sensitive) {
            return $row;
        }

        foreach ($row as $key => $value) {
            if (\in_array(strtolower((string) $key), $sensitive, true) && null !== $value && '' !== $value && [] !== $value) {
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
