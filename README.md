# contao-ai-core-bundle

Contao 5 bundle that exposes CMS operations as Symfony console commands. The agnostic operator layer for the **contao-ai** family — programmatic read and write access to pages, articles, news, files, members, and more, without any LLM dependency.

> **Beta software.** Bundle interfaces (command names/options, JSON output schema) may change between minor versions. Use at your own risk in production.

## The contao-ai ecosystem

| Package | What it is | When to use |
|---|---|---|
| **contao-ai-core-bundle** *(this package)* | Contao bundle exposing CMS operations as Symfony console commands. | Required as the foundation layer. Install on any Contao site you want to manage via AI. |
| [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) | Python CLI — connects to Contao via SSH and runs commands. | For developers and agencies: manage Contao from the terminal or hand control to an AI agent. |
| [contao-ai-backend-bundle](https://github.com/webwerkwien/contao-ai-backend-bundle) | Contao backend module — browser-based AI chat interface (Anthropic Claude, OpenAI). | For editors and admins: AI directly inside the Contao backend, no SSH or terminal needed. |

## What it does

contao-ai-core-bundle exposes Contao 5 CMS operations as Symfony console commands. It is the bridge layer between AI agents and the Contao CMS — used via SSH by [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli), or called in-process by [contao-ai-backend-bundle](https://github.com/webwerkwien/contao-ai-backend-bundle).

## Requirements

- PHP ^8.2
- Contao ^5.3

## Installation

```bash
composer require webwerkwien/contao-ai-core-bundle
```

## Available Commands

All commands output JSON and follow a consistent `{"status":"ok", ...}` / `{"status":"error", ...}` format.

> **One caveat on reading:** a `tinyint` column comes back as `true`/`false`, never as a number, because Doctrine maps every `tinyint` to boolean and Contao casts accordingly on read. A record storing `stunden = 2` answers `{"stunden": true}` — the stored value is unharmed, only this reading of it is lossy. For flags (`published`, `hide`) that is correct; it bites where a project declared a *number* as `tinyint`. Do not write such a value back (`2` returns as `true` and would be stored as `1`); setting a field outright with `--set stunden=2` is unaffected, as it passes a string. Declare numeric fields as `smallint(5) unsigned`, which is what Contao's own DCA does.

| Area | Commands |
|---|---|
| Pages | `contao:page:read` `contao:page:create` `contao:page:update` `contao:page:delete` `contao:page:publish` |
| Articles | `contao:article:read` `contao:article:create` `contao:article:update` `contao:article:delete` |
| Content elements | `contao:content:read` `contao:content:create` `contao:content:update` `contao:content:delete` |
| News | `contao:news:read` `contao:news:create` `contao:news:update` `contao:news:delete` |
| Events | `contao:event:read` `contao:event:create` `contao:event:update` `contao:event:delete` |
| FAQ | `contao:faq:read` `contao:faq:create` `contao:faq:update` `contao:faq:delete` |
| Members | `contao:member:update` `contao:member:delete` |
| Users | `contao:user:update` `contao:user:delete` |
| Files | `contao:file:read` `contao:file:write` `contao:file:meta` `contao:file:process` `contao:folder:create` |
| Templates | `contao:template:list` `contao:template:read` `contao:template:write` |
| Comments | `contao:comment:delete` `contao:comment:publish` |
| Layout | `contao:layout:read` |
| Versions | `contao:version:list` `contao:version:read` `contao:version:create` `contao:version:restore` |
| Search | `contao:search:query` |
| Schema / Config | `contao:dca:schema` `contao:listing:config` |
| Macros (since v0.2.0) | `contao:record:list` `contao:record:clone` |

### Macro commands

- `contao:record:list <table>` — table-agnostic listing with Doctrine-parameterised filters, DCA-validated ORDER BY, pagination, curated default columns per table.
- `contao:record:clone <table> <id> [--recursive]` — clone a container record (news archive, calendar, FAQ category, page) including all cascading children in one DB transaction. With `--recursive` PageCloner walks subpage trees (depth-cap 10, total-cap 50). Tagged-iterator EntityCloner registry — only registers cloners for plugins actually installed on the target site.

> **`record_rewrite` lives in [contao-ai-backend-bundle](https://github.com/webwerkwien/contao-ai-backend-bundle), not here.** That command needs an LLM API key per call. Keeping core agnostic of LLM dependencies and key handling was a deliberate architecture decision.

## Declaring a contract for your own command

If your extension ships a `contao:*` console command, `contao:ai:commands --name=…`
already answers its name, description, arguments and options — Symfony knows those.
What it cannot know is what the command *does*, and an AI agent reaching for it has
to guess. An optional attribute closes that gap.

```php
#[AsCommand(name: 'contao:shop:confirm', description: 'Confirm a pending order')]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    writes: true,
    tables: ['tl_shop_order', 'tl_shop_voucher'],
    trace: ['tl_log'],
    traceWhen: 'before',
    irreversible: 'sends a confirmation mail to the customer',
    repeatable: false,
    answerShape: ['status', 'id'],
    genericPathUnsuitable: ['tl_shop_order' => 'the transitions hang on save_callbacks'],
)]
class ConfirmOrderCommand extends Command { /* … */ }
```

**And it is what makes your command reachable.** `contao:ai:run` runs commands under
`contao:` and commands that declare a contract. Keep your own prefix — that is the
convention, and `contao:` is Contao's property — and declare instead. The prefix says who
wrote a command; only the declaration says its author meant it to be driven this way.

**It costs you no dependency.** PHP resolves an attribute class only on
`newInstance()`; this bundle reads the raw arguments and never instantiates. So the
attribute above works whether or not `webwerkwien/contao-ai-core-bundle` is in your
`require`, and an installation without this bundle is unaffected. Write the
fully-qualified name as shown and add no dependency.

### The answer separates what was checked from what was claimed

A declaration nobody verifies is an assertion, and a test run only ever observes the
happy path. So the three are kept apart rather than flattened into one object:

| Block | Meaning |
| --- | --- |
| `checked` | held against this installation — the named tables have a DCA here, or they do not |
| `checked_with_statement` | observable on the happy path, plus a statement about the rest. `traceWhen` describes the failure path without having to trigger one, and the retention period is **read from this site's configuration**, never declared |
| `declared` | `irreversible` and `repeatable` can never be verified from outside. They stay the command's own word, and the output says so |

`trace: ['tl_log']` and `trace: ['tl_version']` are not interchangeable: Contao keeps
them 7 and 90 days respectively. The period belongs to the installation, so the bundle
reads it rather than letting a command claim its own.

### It describes a command, not every write path

The presented answer carries a `covers` field saying so. In a site bundle the riskier
write paths are usually **not** console commands — a status transition on a DCA
`button_callback`, a front-end controller handling a POST, a cron job — and none of them
can carry a contract. Collecting every contract on an installation gives a picture of its
commands, not of everything that writes.

### What does not belong in it

Business rules. Lead times, seasonal notices, "a voucher covering the full amount makes
the payment method unnecessary" — those belong in the command's *description*. A
contract shaped around one consumer's domain stops being a contract.

### Malformed entries are reported, not dropped

A field with the wrong type comes back under `problems` and is left out; the rest of the
contract still stands. A contract that silently loses a field looks complete and is not.

## Audit trail

Every successful write leaves two traces.

**Contao's system log (`tl_log`)**, visible in the back end under *System > System log*:

| Column | Value |
| --- | --- |
| `source` | `CLI` for console and macro-bridge writes, shown as *Command line* / *Kommandozeile* in the origin filter — Contao itself only writes `BE` and `FE`, so they are filterable on their own. When contao-ai-backend-bundle runs the same command inside a back-end request, Contao labels it `BE` as usual. |
| `action` | `GENERAL` for records, `FILES` for file, folder and template commands |
| `username` | the `--operator` when one is passed, otherwise the shell user (`$_SERVER['USER']`). Every write command accepts it. |
| `func` | the command name, e.g. `contao:page:update` |
| `text` | command name plus the JSON payload the command returned |

**A version snapshot (`tl_version`)** for the ten tables `VersionManager` covers, restorable
with `contao:version:restore`, plus a `tl_undo` entry for deletions.

Failed commands are not logged — a rejected `--set` changed nothing.

## Tests

```bash
vendor/bin/phpunit
```

Runs in two modes, and the difference is not cosmetic.

**Without `CONTAO_ROOT`** the framework is mocked away. Everything that only checks
argument handling and output shape runs; the 17 tests that resolve a model through
`Model::findById()` are **skipped**, because that call reaches `DcaExtractor` and needs
a real Symfony container and a database.

**With `CONTAO_ROOT`** pointing at a Contao installation, its kernel is booted and the
whole suite runs:

```bash
CONTAO_ROOT=/var/www/example/web vendor/bin/phpunit
```

The package ships without `tests/` (`export-ignore`), so to run this mode against an
installation, copy `tests/` and `phpunit.xml.dist` somewhere, install PHPUnit there, and
point `CONTAO_ROOT` at the installation.

Verified on Contao 5.7.11: 151 tests, 252 assertions, no skips.

## License

MIT — see [LICENSE](LICENSE).

This software is provided "as is", without warranty of any kind. The authors accept no liability for any damages arising from its use.
