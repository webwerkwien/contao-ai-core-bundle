# contao-ai-core-bundle

Contao 5 console commands for agent-driven CRUD. This is the **execution layer**
for both clients: the in-browser agent of `contao-ai-backend-bundle` and the
Python `contao-ai-cli` over SSH. Nothing here talks to a model; it exposes
Contao's own write paths as commands an agent can call.

## Commands

```bash
composer ci        # the gate — PHPStan level 6 then PHPUnit, both must pass
composer phpstan   # static analysis alone
composer phpunit   # tests alone
```

`composer ci` needs no extra flags. The memory limit PHPStan requires and the
`allow-plugins` entry it needs are both in the repository — if the command asks
you for either, something is wrong with the checkout, not with your invocation.

> ⚠️ **Read the whole output, not just the last line.** This package is large
> enough that PHPStan analyses it in worker processes. When one of those dies,
> the run still ends with a count — and a count is what `tail` and `grep` show
> you, while the reason stays further up.
>
> On 2026-09-05, when static analysis was introduced here, that count was
> `[ERROR] Found 4 errors`. For 210 files it read like a well-kept package. It
> was a crash: PHPStan 1.x is not PHP 8.4 compatible in its workers, and it said
> so only in lines nobody had scrolled to.
>
> **The pinned `phpstan/phpstan: ^2.1` is the fix for that**, and it is why the
> constraint is not loosened casually: 2.x prints *Result is incomplete because
> of severe errors* when a worker dies, so the failure announces itself. The
> habit stays useful anyway — the number is still the last thing on screen, and
> a plausible number is more dangerous than an implausible one. `2 of 56` invited
> a second look. `4 of 210` did not.

## Verifying your work

Run `composer ci` before reporting any task complete, and paste the output.

Healthy output has two halves, PHPStan first and PHPUnit second:

```
 [OK] No errors

OK, but some tests were skipped!
Tests: …, Assertions: …, Skipped: …
```

The skips are expected — they are the tests that need a booted Contao framework
and skip themselves without one. The counts are deliberately not written down;
they change with every commit, and a documented figure would be wrong by the
next one. What must hold is the shape: `[OK] No errors`, then `OK`.

A failure is a failure — fix the code, never the test. And where PHPStan calls a
defensive check redundant, read the entry in `phpstan.neon.dist` before removing
it: several of those guards exist because a declared type is a promise the
runtime does not keep.

**For a bug fix, write the failing test first.** Reproduce the bug as a test, run
it, confirm it fails for the reason you expect, and commit that test before
touching the implementation. Do not edit test files while making the fix.

## Conventions

- PHP 8.2+, `declare(strict_types=1)` in every file, `Command::SUCCESS` rather
  than `return 0`.
- A write goes through Contao's record writer, never through raw SQL — that is
  what produces the `tl_version` entry the whole bundle exists for.
- Log labels are bilingual: `contao/languages/de/tl_log.xlf` and `en/`, same ids
  in both. Console output is English — it addresses agents and developers, not
  editors. (Where exactly that boundary runs has not been decided; ask before
  translating command output.)
- Every scanning test needs a counter and at least one known non-match. A search
  that finds nothing passes exactly like one that finds everything.

## What `--set` refuses

Every write command runs the caller's fields through `convertFields()` before
anything reaches the database. Four rules refuse rather than write, each of them
a rule Contao has in the DCA and loses when a write goes around `DC_Table`:

| rule | refuses |
|---|---|
| `refuseUnknownFields()` | a field that is not a column of the table |
| `refuseInvalidValues()` | a value failing the field's `eval.rgxp` |
| `refuseInvalidBooleans()` | anything but `1`, `0` or empty for a `sql.type => boolean` column |
| `refuseTakenUniqueValues()` | a duplicate in a `eval.unique` field |

All four answer with `{"status":"error"}` and exit 1, and nothing is written.

> ⚠️ **Booleans take `1` or `0` — not `true`, `yes` or `on`.** From v0.7.0 those
> are refused with a message naming the field. This is stricter than it looks
> necessary, and the reason is Contao 6: it casts a value into the column's
> declared type instead of letting the database refuse it, so
> `--set published=vielleicht` became `published=true` and reported success.
> Measured on 2026-09-05 — on Contao 5.7.13 the same input was an error, so this
> is a Contao-6-only silent failure. An empty value (`--set published=`) is
> accepted and means 0, because that is what an unchecked checkbox submits.

## Things that go wrong here

Both of the following are already pinned by tests. Extend those tests when you
add a command; do not write new ones for the same rule.

**1. A command that needs an optional Contao bundle must be excluded by filename
pattern — and the pattern is a filename, not a concept.**

`services.yaml` auto-discovers `../src` and excludes the commands depending on
`news`, `calendar`, `faq` or `comments` by pattern; `loadExtension()` then loads
`services_<bundle>.yaml` only when that bundle is installed.

On 2026-08-31 four new `tl_calendar` commands were named `Calendar*Command`,
while the calendar exclusion reads `Event*.php` — the name the existing commands
happened to have. All four slipped through and were registered on installations
with no calendar bundle at all.

> **Neither half of that failure is visible from reading the YAML.** A new
> command whose name does not match the existing family is the dangerous case.

Enforced by `tests/Command/PluginCommandRegistrationTest.php`.

**2. A command that calls the writer must call `$this->framework->initialize()`.**

The writer resolves its model class through `$GLOBALS['TL_MODELS']`, which is
empty until the framework is initialised. A command converted from raw SQL to the
writer does not inherit the initialisation, because raw SQL never needed one.

This shipped once and died on the first real run, with 603 green unit tests and a
clean `--dry-run` behind it — the tests mock the framework away, and a dry run
writes nothing and therefore never reaches the writer.

Enforced by `tests/Command/WritePathTest.php`, which pairs the two statically.

**3. A verification claim is not a verification.**

The `Contract` classes exist to keep "the command writes a version" apart from
"the command claims it writes a version". Do not flatten them into one object —
a caller cannot recover the difference afterwards.
