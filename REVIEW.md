# Review instructions

How to review a change in this repository. Findings do not approve or block on
their own — a human decides.

## Passes

Run three passes and tag every finding with the pass it came from.

**Bugs** — logic errors, broken edge cases, subtle regressions. Pay particular
attention to anything whose behaviour depends on which optional Contao bundle is
installed, and to any command that writes.

**Security** — unvalidated input reaching a console command or a query, a write
path that bypasses the record writer, secrets or personal data in output or
logs, a field allowlist that lets through something it should not.

**Compliance** — does the change do what its commit message and CHANGELOG entry
say it does, no more and no less? Undeclared behaviour changes belong here, not
in Bugs.

## What Important means here

Reserve **Important** for findings that would break behaviour, leak data, or ship
a defect to an installation that differs from the developer's own. Everything
else is a nit.

Specifically Important, because each has happened:

- A new command that depends on an optional Contao bundle and whose **filename
  does not match the existing exclusion pattern** — the 2026-08-31 case. Check
  the pattern, not the intent.
- A command that calls the writer without `$this->framework->initialize()`.
- A write that goes around the record writer, so no `tl_version` entry is
  produced.
- A verification claim without the command output that backs it.
- A check that cannot fail under the conditions it runs in.
- A scanning test without a counter, or without a known non-match beside it.

## Cap the nits

At most five nits per review; summarise the rest as a count.

## Do not report

- Style and naming, unless it contradicts a convention in `CLAUDE.md`.
- Anything `composer ci` already enforces — it is green, so a finding it would
  have caught means the command was not run, and *that* is the finding.
- The `ignoreErrors` entries in `phpstan.neon.dist`. Each carries its reason;
  propose a change to the reason, not to the entry. Several of them exist
  because a declared type is a promise the runtime does not keep — removing the
  guard they cover would be the dangerous repair, not the tidy one.

## On test changes

Treat any edit to an existing test as a finding worth a look. Weakening a check
to make a fix pass is the failure mode this section exists for. A deleted or
loosened assertion needs a reason in the commit message.

The two pairing tests — `PluginCommandRegistrationTest` and `WritePathTest` —
each exist because the rule they enforce was broken in production once. A change
that narrows either of them needs more than a passing suite as justification.
