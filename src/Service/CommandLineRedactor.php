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
 * What counts as a secret: a name that reads like one (password, secret, token,
 * api key, credential …), and what a command declares in `#[AiContract(secrets: …)]`.
 * Both as an option (`--api-key x`, `--api-key=x`) and as the key of a `key=value`
 * pair (`--set password=x`, `--set=password=x`), the form this bundle's commands take
 * fields in. Short options (`-p x`) are not recognised.
 *
 * ⚠️ This keeps the secret out of the log, nothing more. The line still travels to
 * the server as a process argument, and from the back-end chat to the model provider.
 * A secret is better read from a file or the environment than passed on a command line.
 */
final class CommandLineRedactor
{
    public const MASK = '***';

    /** Names that read as a secret wherever they appear. Over-blanking costs a detail in the log, nothing else. */
    private const SECRET = '/pass(?:word|wort|phrase)|secret|token|api[-_]?key|credential|private[-_]?key/i';

    /** A shell-like token: bare text and quoted parts, glued together. */
    private const TOKEN = '/(?:[^\s"\']+|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\')+/';

    /**
     * @param list<string> $declared names the command declares secret, with or without `--`
     */
    public static function redact(string $line, array $declared = []): string
    {
        $declared = array_map(static fn (string $name): string => strtolower(ltrim($name, '-')), $declared);
        $isSecret = static fn (string $name): bool => \in_array(strtolower(ltrim($name, '-')), $declared, true)
            || 1 === preg_match(self::SECRET, $name);

        preg_match_all(self::TOKEN, $line, $matches, \PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];

        /** @var array<int, string> $replace token index => replacement */
        $replace = [];

        foreach ($tokens as $n => [$raw]) {
            $text = self::unquote($raw);

            if (preg_match('/^(--[^=\s]+)=(.*)$/s', $text, $m)) {
                // --name=value, or --name=key=value
                if ($isSecret($m[1])) {
                    $replace[$n] = $m[1] . '=' . self::MASK;
                } elseif (null !== ($pair = self::blankedPair($m[2], $isSecret))) {
                    $replace[$n] = $m[1] . '=' . $pair;
                }

                continue;
            }

            if (str_starts_with($text, '--')) {
                // --name value: the next token is the value, unless it is an option itself.
                if ($isSecret($text) && isset($tokens[$n + 1]) && !str_starts_with(self::unquote($tokens[$n + 1][0]), '-')) {
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
            [$raw, $offset] = $tokens[$n];
            $line = substr_replace($line, $replacement, $offset, \strlen($raw));
        }

        return $line;
    }

    /**
     * @param \Closure(string): bool $isSecret
     */
    private static function blankedPair(string $text, \Closure $isSecret): ?string
    {
        if (!preg_match('/^([A-Za-z_][\w.-]*)=/', $text, $m) || !$isSecret($m[1])) {
            return null;
        }

        return $m[1] . '=' . self::MASK;
    }

    /** The token as the shell would hand it over: quotes removed, `\"` unescaped. */
    private static function unquote(string $raw): string
    {
        return (string) preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'/',
            static fn (array $m): string => isset($m[2]) ? $m[2] : stripcslashes($m[1]),
            $raw,
        );
    }
}
