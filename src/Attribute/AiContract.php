<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Attribute;

/**
 * What an extension's console command promises about itself.
 *
 * `contao:ai:commands` already answers name, description, arguments and
 * options — Symfony knows those. This carries what Symfony cannot know, and
 * every field here exists because its absence forced a caller to guess.
 *
 * ## It costs the declaring extension nothing
 *
 * PHP resolves an attribute class only on `newInstance()`. `getArguments()`
 * reads the raw values without it, and this bundle never instantiates. So an
 * extension can carry this attribute **without requiring contao-ai-core-bundle
 * at all** — verified on PHP 8.4 before the design was settled. A plugin that
 * declares does not take on a dependency, and one installed where this bundle
 * is absent loses nothing.
 *
 * The class exists anyway, for editors, for named-argument checking, and so
 * the field names have one authoritative spelling.
 *
 * ## What does not belong in here
 *
 * Business rules. Lead times, seasonal notices, "a voucher covering the full
 * amount makes the payment method unnecessary" — those belong in the command's
 * *description*, not in a machine-readable promise. A contract shaped around
 * one consumer's domain stops being a contract.
 *
 * ## Three classes of assurance, and they are not equal
 *
 * - **checked** — `tables` can be held against the DCA
 * - **checked, plus a statement** — `trace` and `traceWhen` are observable on
 *   the happy path; *when* the entry is written describes the failure path
 *   without having to trigger it, and the retention period is read from
 *   Contao's own configuration
 * - **declared** — `irreversible` and `repeatable` can never be verified from
 *   outside and stay assertions. The output says so rather than presenting
 *   them like the rest
 */
/*
 * Classes and methods.
 *
 * A console command is one class, so the class is the natural place. A tool
 * class in contao-ai-backend-bundle is not: `ArticleTool` carries four tools —
 * create, update, delete, read — behind four methods, and they make very
 * different promises. Declaring at class level there would force one contract
 * to cover a read and a cascading delete.
 *
 * Widening the target is backward compatible: every existing class-level
 * declaration keeps working, and `ContractReader` looks at the method first,
 * falling back to the class.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class AiContract
{
    /**
     * @param bool                       $writes       Does it write to the database at all
     * @param list<string>               $tables       Tables it touches — checkable against the DCA
     * @param list<string>               $trace        Which of tl_version / tl_undo / tl_log it leaves behind
     * @param string|null                $traceWhen    'before' or 'on-success' — see below
     * @param string|null                $irreversible Effect outside the database that cannot be taken back,
     *                                                 in plain words. null means none is claimed
     * @param bool|null                  $repeatable   May it be re-run after an abort
     * @param array<string, list<string>> $optionValues Allowed values per option, only where no DCA states them
     * @param list<string>               $answerShape  Keys the JSON answer carries
     * @param array<string, string>      $genericPathUnsuitable Table => why a generic writer must not touch it
     */
    public function __construct(
        public readonly bool $writes = false,
        public readonly array $tables = [],
        public readonly array $trace = [],
        // 'before' is the stronger promise and the reason this field exists:
        // an entry written afterwards records only the runs that went well,
        // which is the opposite of what a trail is for. contao:ai:run writes
        // its own log line before starting for exactly that reason.
        public readonly ?string $traceWhen = null,
        // The field a caller has to stop at. A database write has tl_undo; a
        // sent email has nothing. Both this bundle and the ww-buchung session
        // arrived at it independently, which is the best evidence available
        // that it is general rather than one project's special case.
        public readonly ?string $irreversible = null,
        public readonly ?bool $repeatable = null,
        public readonly array $optionValues = [],
        public readonly array $answerShape = [],
        // Said by the extension about its own table, and it bites before a
        // wrapper exists rather than after: "for tl_ww_buchung the generic
        // path is unsuitable, the transitions hang on save_callbacks."
        public readonly array $genericPathUnsuitable = [],
    ) {
    }
}
