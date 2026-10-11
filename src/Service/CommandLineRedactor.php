<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

/**
 * Blanks the values of secrets in a command line before it is logged (v1.5.0).
 *
 * `contao:ai:run` writes the line it starts to `tl_log`, before the run, on purpose
 * (see AiRunCommand). A command that takes a password or an API key as an option —
 * `--set password=…`, `--api-key=…` — left it there in plain text: readable for every
 * admin in the system log, kept for the log period, and in every database backup
 * taken meanwhile. Reported by the Consho session, 2026-10-11.
 *
 * What counts as a secret: a name that reads like one (password, passwd, pass,
 * secret, token, api key, credential …), and what a command declares in
 * `#[AiContract(secrets: …)]`. Both as an option (`--api-key x`, `--api-key=x`) and as
 * the key of a `key=value` pair (`--set password=x`, `--set=password=x`), the form this
 * bundle's commands take fields in. Short options (`-p x`) are not recognised.
 *
 * The line is split the way Symfony's StringInput splits it — backslash escapes, both
 * quote kinds, glued parts — character by character: what the command reads as
 * `password=…` is what is blanked here. A first version used a regular expression and
 * gave the line back unchanged when a quoted value passed 8 KB (PCRE's JIT stack), a
 * secret after it included (Fable review before v1.5.0).
 *
 * ⚠️ This keeps the secret out of the log, nothing more. The line still travels to
 * the server as a process argument, and from the back-end chat to the model provider.
 * A secret is better read from a file or the environment than passed on a command line.
 */
final class CommandLineRedactor
{
    public const MASK = '***';

    /**
     * Names that read as a secret wherever they appear. `pass` only as a word of its
     * own (`smtp-pass`, not `passive`). Over-blanking costs a detail in the log, nothing else.
     */
    public const SECRET = '/passw(?:or)?d|passwort|passphrase|pass(?![a-z])|secret|token|api[-_]?key|credential|private[-_]?key/i';

    /**
     * @param list<string> $declared names the command declares secret, with or without `--`
     */
    public static function redact(string $line, array $declared = []): string
    {
        $declared = array_map(static fn (string $name): string => strtolower(ltrim($name, '-')), $declared);
        $isSecret = static fn (string $name): bool => \in_array(strtolower(ltrim($name, '-')), $declared, true)
            || 1 === preg_match(self::SECRET, $name);

        $tokens = self::tokens($line);

        /** @var array<int, string> $replace token index => replacement */
        $replace = [];

        foreach ($tokens as $n => [, , $text]) {
            if (str_starts_with($text, '--') && false !== ($eq = strpos($text, '='))) {
                // --name=value, or --name=key=value
                $name = substr($text, 0, $eq);

                if ($isSecret($name)) {
                    $replace[$n] = $name . '=' . self::MASK;
                } elseif (null !== ($pair = self::blankedPair(substr($text, $eq + 1), $isSecret))) {
                    $replace[$n] = $name . '=' . $pair;
                }

                continue;
            }

            if (str_starts_with($text, '--')) {
                // --name value: the next token is the value, unless it is an option itself.
                if ($isSecret($text) && isset($tokens[$n + 1]) && !str_starts_with($tokens[$n + 1][2], '-')) {
                    $replace[$n + 1] = self::MASK;
                }

                continue;
            }

            if (!isset($replace[$n]) && null !== ($pair = self::blankedPair($text, $isSecret))) {
                // key=value as the value of an option (--set password=x) or an argument.
                $replace[$n] = $pair;
            }
        }

        if ([] === $replace) {
            return $line;
        }

        // From the end, so the offsets before stay valid; the rest of the line keeps
        // the caller's spelling.
        krsort($replace);

        foreach ($replace as $n => $replacement) {
            [$offset, $length] = $tokens[$n];
            $line = substr_replace($line, $replacement, $offset, $length);
        }

        return $line;
    }

    /**
     * @param \Closure(string): bool $isSecret
     */
    private static function blankedPair(string $text, \Closure $isSecret): ?string
    {
        $eq = strpos($text, '=');

        if (false === $eq || 0 === $eq || 1 !== preg_match('/^[\w.-]+$/', $key = substr($text, 0, $eq)) || !$isSecret($key)) {
            return null;
        }

        return $key . '=' . self::MASK;
    }

    /**
     * The line as StringInput reads it: whitespace separates, a backslash escapes the
     * next character, quoted parts (either kind, with backslash escapes inside, passed
     * through stripcslashes) glue to their neighbours.
     *
     * @return list<array{int, int, string}> offset and length in the line, and the token as the command sees it
     */
    private static function tokens(string $line): array
    {
        $tokens = [];
        $length = \strlen($line);
        $i      = 0;

        while ($i < $length) {
            if (ctype_space($line[$i])) {
                ++$i;
                continue;
            }

            $start = $i;
            $text  = '';

            while ($i < $length && !ctype_space($line[$i])) {
                $char = $line[$i];

                if ('\\' === $char) {
                    $text .= $line[$i + 1] ?? '';
                    $i += 2;
                    continue;
                }

                if ('"' === $char || "'" === $char) {
                    $quoted = '';
                    ++$i;

                    while ($i < $length && $line[$i] !== $char) {
                        if ('\\' === $line[$i] && $i + 1 < $length) {
                            $quoted .= $line[$i] . $line[$i + 1];
                            $i += 2;
                            continue;
                        }

                        $quoted .= $line[$i++];
                    }

                    ++$i; // the closing quote
                    $text .= stripcslashes($quoted);
                    continue;
                }

                $text .= $char;
                ++$i;
            }

            $tokens[] = [$start, min($i, $length) - $start, $text];
        }

        return $tokens;
    }
}
