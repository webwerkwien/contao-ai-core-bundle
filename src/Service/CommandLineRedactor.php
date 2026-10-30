<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\StringInput;

/**
 * Blanks the values of secrets in a command line before it is logged (v1.5.0).
 *
 * `contao:ai:run` writes the line it starts to `tl_log`, before the run, on purpose
 * (see AiRunCommand). A command that takes a password or an API key as an option —
 * `--set password=…`, `--api-key=…` — left it there in plain text: readable for every
 * admin in the system log, kept for the log period, and in every database backup
 * taken meanwhile. Reported from the Consho project.
 *
 * What counts as a secret: a name that reads like one (password, passwd, pass,
 * secret, token, api key, credential …), and what a command declares in
 * `#[AiContract(secrets: …)]`. Both as an option (`--api-key x`, `--api-key=x`) and as
 * the key of a `key=value` pair (`--set password=x`, `--set=password=x`), the form this
 * bundle's commands take fields in.
 *
 * Short options (v1.14.0): `-p x`, `-px` and `-rp x` are blanked when `p` is passed in
 * `$declared` — {@see secretShortcuts()} reads which letters stand for a secret from the
 * command's definition. Until then `contao:user:password admin -p x`, run through
 * `contao:ai:run`, reached `tl_log` in plain text; Contao's own password commands have
 * that shortcut. A password in a URL (`mysql://user:x@host`) is blanked in any value.
 *
 * The line is split the way Symfony's StringInput splits it — backslash escapes, both
 * quote kinds, glued parts — character by character: what the command reads as
 * `password=…` is what is blanked here. A first version used a regular expression and
 * gave the line back unchanged when a quoted value passed 8 KB (PCRE's JIT stack), a
 * secret after it included.
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

    /** The password part of a URL's user info: `scheme://user:password@`. */
    private const URL_PASSWORD = '#([a-z][a-z0-9+.-]*://[^\s/:@]*:)([^\s/@]+)@#i';

    /**
     * The shortcut letters of the options that are secrets — by name or declared — for
     * `$declared` of {@see redact()}.
     *
     * @param list<string> $declared
     *
     * @return list<string>
     */
    public static function secretShortcuts(InputDefinition $definition, array $declared = []): array
    {
        $declared = array_map(static fn (string $name): string => strtolower(ltrim($name, '-')), $declared);
        $letters  = [];

        foreach ($definition->getOptions() as $option) {
            $shortcut = $option->getShortcut();

            if (null === $shortcut || '' === $shortcut) {
                continue;
            }

            if (\in_array(strtolower($option->getName()), $declared, true) || 1 === preg_match(self::SECRET, $option->getName())) {
                // "p|x" for two shortcuts of one option
                array_push($letters, ...explode('|', $shortcut));
            }
        }

        return array_values(array_unique($letters));
    }

    /**
     * @param list<string> $declared names the command declares secret, with or without `--`,
     *                               and shortcut letters of secret options
     */
    public static function redact(string $line, array $declared = []): string
    {
        return self::redacted($line, $declared)[0];
    }

    /**
     * The line blanked, and what was blanked in it (v1.6.0): the values as the command
     * reads them, so they can be struck from an error message that quotes one —
     * Symfony's *No arguments expected, got "password=…"*. Null instead of the values
     * when the arguments were withheld: then nothing says what to strike.
     *
     * @param list<string> $declared names the command declares secret, with or without `--`
     *
     * @return array{string, list<string>|null}
     */
    public static function redacted(string $line, array $declared = []): array
    {
        $declared = array_map(static fn (string $name): string => strtolower(ltrim($name, '-')), $declared);
        $isSecret = static fn (string $name): bool => \in_array(strtolower(ltrim($name, '-')), $declared, true)
            || 1 === preg_match(self::SECRET, $name);

        $values   = [];
        $withheld = false;
        $line     = self::redactLine($line, $isSecret, 0, $values, $withheld);

        return [$line, $withheld ? null : array_values(array_unique($values))];
    }

    /**
     * @param \Closure(string): bool $isSecret
     * @param list<string>           $values   collects what is blanked
     */
    private static function redactLine(string $line, \Closure $isSecret, int $depth, array &$values, bool &$withheld): string
    {
        $tokens = self::tokens($line);

        // Blank only what can be located for sure. Where this split and Symfony's
        // differ — a quirk of StringInput (adjacent quote pairs, a backslash before a
        // line break) — or the line is absurdly long, the arguments are withheld
        // whole: a log entry with less detail, never one with the secret.
        if (\count($tokens) > self::MAX_TOKENS || !self::splitsLikeSymfony($line, $tokens)) {
            $withheld = true;

            return self::withheld($tokens, $line);
        }

        /** @var array<int, string> $replace token index => replacement */
        $replace = [];

        foreach ($tokens as $n => [, , $text]) {
            if (str_starts_with($text, '--') && false !== ($eq = strpos($text, '='))) {
                // --name=value, or --name=key=value
                $name = substr($text, 0, $eq);

                if ($isSecret($name)) {
                    $replace[$n] = $name . '=' . self::MASK;
                    $values[]    = substr($text, $eq + 1);
                } elseif (null !== ($value = self::blankedValue(substr($text, $eq + 1), $isSecret, $depth, $values, $withheld))) {
                    $replace[$n] = $name . '=' . $value;
                }

                continue;
            }

            if (str_starts_with($text, '--')) {
                // --name value: the next token is the value, unless it is an option itself.
                if ($isSecret($text) && isset($tokens[$n + 1]) && !str_starts_with($tokens[$n + 1][2], '-')) {
                    $replace[$n + 1] = self::MASK;
                    $values[]        = $tokens[$n + 1][2];
                }

                continue;
            }

            if (str_starts_with($text, '-') && \strlen($text) > 1) {
                // -p value, -pvalue, -rp value: from the first secret letter on, the rest
                // of the token is its value, or else the next token. Symfony reads a
                // letter before it as a flag or as an option that takes the rest — either
                // way nothing after a secret letter is left standing.
                foreach (str_split(substr($text, 1)) as $k => $letter) {
                    if (!$isSecret($letter)) {
                        continue;
                    }

                    $rest = substr($text, $k + 2);

                    if ('' !== $rest) {
                        $replace[$n] = substr($text, 0, $k + 2) . self::MASK;
                        $values[]    = ltrim($rest, '=');
                    } elseif (isset($tokens[$n + 1]) && !str_starts_with($tokens[$n + 1][2], '-')) {
                        $replace[$n + 1] = self::MASK;
                        $values[]        = $tokens[$n + 1][2];
                    }

                    break;
                }

                continue;
            }

            // key=value as the value of an option (--set password=x) or an argument.
            if (!isset($replace[$n]) && null !== ($value = self::blankedValue($text, $isSecret, $depth, $values, $withheld))) {
                $replace[$n] = $value;
            }
        }

        // A password in a URL, in any token nothing else blanked: `--dsn=mysql://u:x@h`
        // has a harmless option name, so only the value tells.
        foreach ($tokens as $n => [, , $text]) {
            if (isset($replace[$n]) || 1 !== preg_match(self::URL_PASSWORD, $text)) {
                continue;
            }

            $blanked     = (string) preg_replace_callback(self::URL_PASSWORD, static function (array $m) use (&$values): string {
                $values[] = $m[2];

                return $m[1] . self::MASK . '@';
            }, $text);
            $replace[$n] = 1 === preg_match('/\s/', $blanked) ? "'" . str_replace("'", "'\\''", $blanked) . "'" : $blanked;
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

    /** More tokens than any command line of this bundle has; past it the arguments are withheld. */
    private const MAX_TOKENS = 5000;

    public const WITHHELD = '[arguments withheld: the line could not be checked for secrets]';

    /**
     * A value read as one pair first, then as a line. In that order: `"password=a b"`
     * read as a line would blank `a` and leave `b`, a part of the secret. A pair whose
     * key merely contains a secret's name (`"user:create --set password=x"`) is blanked
     * whole — less detail, nothing kept. Only a value whose first key is harmless
     * (`'note=my password=x'`, `'user:create --set name=a --set password=x'`) is split.
     *
     * @param \Closure(string): bool $isSecret
     * @param list<string>           $values
     */
    private static function blankedValue(string $text, \Closure $isSecret, int $depth, array &$values, bool &$withheld): ?string
    {
        $pair = self::blankedPair($text, $isSecret, $values);

        if (null === $pair) {
            return self::blankedLine($text, $isSecret, $depth, $values, $withheld);
        }

        // Blanked whole, but read as a line as well, for the values alone: the pair's
        // value runs to the end (`S3CRET --set name=a --pin P1N`), and a message quoting
        // only `P1N` would not be struck otherwise.
        // Only where the "key" itself has whitespace, i.e. is the start of a line;
        // `"password=S3 CRET"` is one value with a space in it.
        // Its own withheld flag: the pair is blanked already, and an inner line that
        // cannot be split only means its parts are not collected — the whole value is.
        if ($depth < self::MAX_DEPTH && 1 === preg_match('/\s/', strstr($text, '=', true) ?: '')) {
            $innerWithheld = false;
            self::redactLine($text, $isSecret, $depth + 1, $values, $innerWithheld);
        }

        return $pair;
    }

    /**
     * @param \Closure(string): bool $isSecret
     * @param list<string>           $values
     */
    private static function blankedPair(string $text, \Closure $isSecret, array &$values): ?string
    {
        $eq = strpos($text, '=');

        // Any key, spaces included (`"pass phrase=…"`): what decides is the name.
        if (false === $eq || 0 === $eq || str_starts_with($text, '-') || !$isSecret($key = substr($text, 0, $eq))) {
            return null;
        }

        $values[] = substr($text, $eq + 1);

        return $key . '=' . self::MASK;
    }

    /** Lines inside lines (a quoted value that is itself a command line) this deep are read. */
    private const MAX_DEPTH = 3;

    /**
     * A value with whitespace in it, read as a command line of its own (v1.6.0).
     *
     * Symfony's console error listener logs the line of `contao:ai:run` with the inner
     * line as one quoted value: `--command-line='user:create --set password=x'`. Read
     * as one token, its key is `user:create --set password` — a secret by luck — and
     * with a pair in front (`--set name=a --set password=x`) not even that. So such a
     * value is split and blanked like a line. Over-blanking a text value that merely
     * contains `password=` costs a detail in the log, nothing else.
     *
     * The blanked value comes back single-quoted: it is for the log, not to be run.
     *
     * @param \Closure(string): bool $isSecret
     * @param list<string>           $values
     */
    private static function blankedLine(string $text, \Closure $isSecret, int $depth, array &$values, bool &$withheld): ?string
    {
        if ($depth >= self::MAX_DEPTH || 1 !== preg_match('/\s/', $text)) {
            return null;
        }

        // Only a value with a name in it that could be a secret: a long text, read as a
        // line, would otherwise be withheld for its length alone, with nothing to hide.
        // Should the scan itself fail, the value is read.
        if (false !== preg_match_all('/[\w-]+/', $text, $words)) {
            $candidate = false;

            foreach ($words[0] as $word) {
                if ($isSecret($word)) {
                    $candidate = true;
                    break;
                }
            }

            if (!$candidate) {
                return null;
            }
        }

        $inner = self::redactLine($text, $isSecret, $depth + 1, $values, $withheld);

        if ($inner === $text) {
            return null;
        }

        return "'" . str_replace("'", "'\\''", $inner) . "'";
    }

    /**
     * Whether Symfony's StringInput — which the command actually reads — splits the
     * line into the same tokens. Its token list is private; read the way Symfony's own
     * TraceableCommand reads private state. Without the property (a future Symfony)
     * this split stands alone.
     *
     * @param list<array{int, int, string}> $tokens
     */
    private static function splitsLikeSymfony(string $line, array $tokens): bool
    {
        if (!property_exists(ArgvInput::class, 'tokens')) {
            return true;
        }

        try {
            $theirs = (new \ReflectionProperty(ArgvInput::class, 'tokens'))->getValue(new StringInput($line));
        } catch (\Throwable) {
            return false;
        }

        return array_column($tokens, 2) === $theirs;
    }

    /**
     * The command name and a note instead of the arguments.
     *
     * @param list<array{int, int, string}> $tokens
     */
    private static function withheld(array $tokens, string $line): string
    {
        if ([] === $tokens) {
            return $line;
        }

        // Only a command name stays; a first token that is an option or a pair could
        // carry a secret (contao:ai:run never gets here with one — it needs a name first).
        $first = $tokens[0][2];

        if (str_starts_with($first, '-') || str_contains($first, '=')) {
            return self::WITHHELD;
        }

        return substr($line, $tokens[0][0], $tokens[0][1]) . ' ' . self::WITHHELD;
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
            // Enough to know the line is past the limit; the rest is never read.
            if (\count($tokens) > self::MAX_TOKENS) {
                break;
            }

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
                    $open   = $i;
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

                    if ($i >= $length) {
                        // No closing quote: StringInput takes the quote as a plain
                        // character and splits at the next whitespace.
                        $text .= $char;
                        $i = $open + 1;
                        continue;
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
