<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Contract;

/**
 * Present a contract so that a reader can tell a check from a claim.
 *
 * The objection that shaped this whole design, from the ww-buchung session:
 * *"a declaration nobody verifies is an assertion."* And its sharpening, which
 * corrected the first draft: **a test run observes the happy path.** A command
 * that writes a version on success and not on the failure path passes anyway —
 * and the failure path is the one where the version is needed.
 *
 * So the answer is not one flat object. It is three, and they are labelled:
 *
 * | `checked`               | held against this installation — the tables exist or they do not |
 * | `checked_with_statement`| observable on the happy path, plus a statement about the rest:
 *                             *when* the entry is written describes the failure path without
 *                             having to trigger one, and the retention period is read here |
 * | `declared`              | never verifiable from outside. Stays an assertion, and the
 *                             output says so instead of dressing it up as the rest |
 *
 * Flattening these would produce exactly the failure this project keeps
 * running into: an answer that looks like an answer. "The command writes a
 * version" and "the command claims it writes a version" are not the same
 * sentence, and a caller cannot recover the difference afterwards.
 */
final class ContractPresenter
{
    /**
     * @param array{fields: array<string, mixed>, problems: list<string>} $contract
     * @param callable(string): bool                                     $tableExists
     *
     * @return array<string, mixed>
     */
    public static function present(array $contract, callable $tableExists): array
    {
        $fields = $contract['fields'];
        $out    = [
            'declared_by' => 'the command itself',
            // The boundary of the whole mechanism, said in the answer rather
            // than left to be discovered. Reported by the ww-buchung session,
            // which has five write paths and exactly one console command among
            // them: the status transitions hang on a DCA button_callback, the
            // booking form is a front-end controller, the voucher expiry is a
            // cron job. None of those can carry a contract.
            //
            // A reader collecting every contract on an installation would
            // otherwise hold a picture that looks complete and is not — the
            // same shape this project has spent two days removing.
            'covers' => 'this console command only. A site bundle usually keeps its riskier '
                .'write paths elsewhere — DCA callbacks, front-end controllers, cron jobs — '
                .'and none of those can be declared here.',
        ];

        $out['checked'] = self::checked($fields, $tableExists);

        if ([] !== ($statement = self::withStatement($fields))) {
            $out['checked_with_statement'] = $statement;
        }

        if ([] !== ($declared = self::declared($fields))) {
            $out['declared'] = $declared;
            $out['declared_note'] = 'Nothing outside this installation can confirm these. They are '
                .'the command\'s own word, and are listed apart from the rest for that reason.';
        }

        if ([] !== $contract['problems']) {
            $out['problems'] = $contract['problems'];
            $out['problems_note'] = 'Malformed entries were left out rather than guessed at. The rest '
                .'of the contract still stands.';
        }

        return $out;
    }

    /**
     * @param array<string, mixed>   $fields
     * @param callable(string): bool $tableExists
     *
     * @return array<string, mixed>
     */
    private static function checked(array $fields, callable $tableExists): array
    {
        $checked = [];

        if (isset($fields['tables'])) {
            $checked['tables'] = $fields['tables'];
            // The one field that can be held against the installation right
            // here, so it is. A named table that has no DCA is either a typo
            // or a missing extension, and both are worth knowing before the
            // command runs rather than after.
            $checked['tables_with_dca'] = array_values(array_filter($fields['tables'], $tableExists));
            $missing = array_values(array_diff($fields['tables'], $checked['tables_with_dca']));

            if ([] !== $missing) {
                $checked['tables_without_dca'] = $missing;
                $checked['tables_note'] = 'Declared but no DCA on this installation — a typo, or an '
                    .'extension that is not installed here.';
            }
        }

        if (isset($fields['genericPathUnsuitable']) && [] !== $fields['genericPathUnsuitable']) {
            // Said by the extension before a wrapper exists, rather than
            // discovered after one went around its callbacks.
            $checked['generic_path_unsuitable'] = $fields['genericPathUnsuitable'];
        }

        return $checked;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function withStatement(array $fields): array
    {
        $out = [];

        if (isset($fields['writes'])) {
            $out['writes'] = $fields['writes'];
        }

        if (isset($fields['trace'])) {
            $out['trace'] = $fields['trace'];
            $out['retention'] = TraceRetention::forTraces($fields['trace']);
            $out['retention_note'] = 'Read from this installation, not declared — the period belongs '
                .'to the site, not to the command. "Writes a version" and "writes a log entry" are '
                .'an order of magnitude apart in how long they survive.';
        }

        if (isset($fields['traceWhen'])) {
            $out['trace_when'] = $fields['traceWhen'];
            $out['trace_when_note'] = 'before' === $fields['traceWhen']
                ? 'Written before the run, so a command that crashes still leaves the record that it '
                    .'was started.'
                : 'Written on success only. A run that fails halfway leaves no trace of having '
                    .'started, which is the case a trail is usually wanted for.';
        }

        if (isset($fields['answerShape'])) {
            $out['answer_shape'] = $fields['answerShape'];
        }

        if (isset($fields['optionValues'])) {
            $out['option_values'] = $fields['optionValues'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function declared(array $fields): array
    {
        $out = [];

        if (isset($fields['irreversible'])) {
            $out['irreversible_outside_database'] = $fields['irreversible'];
            // The field a caller has to stop at. A database write has tl_undo;
            // a sent email has nothing.
            $out['irreversible_note'] = 'This cannot be undone by anything in this bundle. Treat it '
                .'as the point to stop and ask.';
        }

        if (isset($fields['repeatable'])) {
            $out['repeatable'] = $fields['repeatable'];
        }

        return $out;
    }
}
