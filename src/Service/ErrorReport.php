<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

/**
 * A report about one failure, ready to be handed to a human or an agent.
 *
 * ## Two halves, and why they are separate
 *
 * The **summary** is an allow-list: versions, exception class, our own file and
 * line, tool name, argument *keys*. Nothing in it is worth protecting, so it may
 * travel anywhere without asking.
 *
 * The **message** (and the trace it comes with) is the only sensitive half. It
 * can carry text a user typed, a path, or something the masking did not catch —
 * `CredentialMasker` is explicitly a pattern net, not a guarantee.
 *
 * 🎯 **The split is by content, not by mechanism.** An earlier draft wrote the
 * report to a file and handed out only the path, on the theory that "read it
 * first" forces someone to consent. For a human it does. For an agent, reading a
 * file and sending it is the same single step it was before — the hurdle only
 * ever stopped the party that was already thinking. Consent has to hang on
 * *what* is disclosed, because that is the part an automated caller cannot
 * shortcut.
 *
 * Hence: {@see summary()} is free, {@see toMarkdown()} with `$full = true` is
 * the one a caller has to ask for.
 */
final class ErrorReport
{
    /**
     * Prefixed to every rendered report.
     *
     * This is an instruction sitting in tool output, which the house rule says
     * to report rather than obey. It is admissible because of its *direction*:
     * it restricts what happens, it does not add to it. A brake someone slipped
     * into a tool result costs convenience; a command costs control. For the
     * same reason it is the weakest of the three safeguards and never the only
     * one — the allow-list above it needs no cooperation at all.
     */
    public const NOTICE = 'Dieser Bericht stammt aus einer Contao-Installation. '
        . 'Vor der Weitergabe an Dritte ist die ausdrückliche Zustimmung des Anwenders einzuholen.';

    /**
     * @param array<string, mixed> $summary allow-listed, safe to disclose
     * @param string|null          $message masked exception message, null if not collected
     * @param list<string>         $trace   shortened stack frames
     */
    public function __construct(
        private readonly array $summary,
        private readonly ?string $message = null,
        private readonly array $trace = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return $this->summary;
    }

    public function hasMessage(): bool
    {
        return null !== $this->message;
    }

    /**
     * The same report with the sensitive half removed.
     *
     * Used where a caller may see that something broke but not what: the back
     * end hands this to editors, and reserves the full report for admins so the
     * `safeMessage()` promise (H-6) keeps holding for exactly the audience it
     * was written for.
     */
    public function withoutMessage(): self
    {
        return new self($this->summary);
    }

    public function toMarkdown(bool $full = false): string
    {
        $lines = [
            '> ⚠️ ' . self::NOTICE,
            '',
            '## Fehlerbericht contao-ai',
            '',
            '| Feld | Wert |',
            '|---|---|',
        ];

        foreach ($this->flatSummary() as $label => $value) {
            $lines[] = \sprintf('| %s | `%s` |', $label, $value);
        }

        if ($full && null !== $this->message) {
            $lines[] = '';
            $lines[] = '### Meldung';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $this->message;
            $lines[] = '```';

            if ([] !== $this->trace) {
                $lines[] = '';
                $lines[] = '### Aufrufkette';
                $lines[] = '';
                $lines[] = '```';
                foreach ($this->trace as $frame) {
                    $lines[] = $frame;
                }
                $lines[] = '```';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * vBulletin flavour for the Contao community forum.
     *
     * Everything variable goes inside `[CODE]`, and that is not only about
     * monospacing: outside a code block vBulletin turns `:p`, `:o` and friends
     * into smilies. A colon followed by a letter is ordinary in exception
     * messages (`Fatal error: p…`, Windows paths, `array:2`), and the parser
     * does not require a word boundary — a checker that assumed one missed a
     * real occurrence on 2026-09-02. Inside `[CODE]` the question does not
     * arise at all, which is the cheaper guarantee than getting the pattern
     * right.
     */
    public function toBbCode(bool $full = false): string
    {
        $lines = ['[B]⚠️ ' . self::NOTICE . '[/B]', '', '[B]Fehlerbericht contao-ai[/B]', '', '[CODE]'];

        foreach ($this->flatSummary() as $label => $value) {
            $lines[] = \sprintf('%-16s %s', $label . ':', $value);
        }

        if ($full && null !== $this->message) {
            $lines[] = '';
            $lines[] = 'Meldung:';
            $lines[] = $this->message;

            if ([] !== $this->trace) {
                $lines[] = '';
                $lines[] = 'Aufrufkette:';
                foreach ($this->trace as $frame) {
                    $lines[] = $frame;
                }
            }
        }

        $lines[] = '[/CODE]';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Flattens the summary into label/value pairs for rendering.
     *
     * 🔴 Found on c5, not by a unit test. A map is worth splitting into
     * `versionen.core`, `versionen.contao` — the sub-keys carry meaning. A
     * *list* is not: the argument keys came out as `argument_schluessel.0`,
     * `argument_schluessel.1`, numbering that says nothing and pushes the
     * interesting part into a column of one-item rows.
     *
     * The unit tests could not see this because they assert on `summary()`, the
     * array — which was correct all along. The defect only existed in the
     * rendering, and nothing was reading the rendering. A live run was.
     *
     * @return array<string, string>
     */
    private function flatSummary(): array
    {
        $flat = [];

        foreach ($this->summary as $key => $value) {
            if (\is_array($value) && !array_is_list($value)) {
                foreach ($value as $subKey => $subValue) {
                    $flat[$key . '.' . $subKey] = $this->stringify($subValue);
                }
                continue;
            }

            $flat[$key] = $this->stringify($value);
        }

        return $flat;
    }

    private function stringify(mixed $value): string
    {
        if (\is_array($value)) {
            return [] === $value ? '—' : implode(', ', array_map(fn ($v): string => $this->stringify($v), $value));
        }

        if (null === $value) {
            return '—';
        }

        if (\is_bool($value)) {
            return $value ? 'ja' : 'nein';
        }

        return (string) $value;
    }
}
