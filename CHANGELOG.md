# Changelog

All notable changes to this project are documented here. The project adheres to [Semantic Versioning](https://semver.org/) (within the pre-1.0 reservations).

This file was reconstructed from the git history on 2026-08-13, so entries before that date describe what the tags contain rather than what was written at release time.

## v0.2.17 - 2026-08-31

### Fixed

- **`contao:record:list` destroyed file references, the same way v0.2.15 had just stopped everything else from doing it.** A `fileTree` column is 16 raw bytes in the database. `outputRecord()` encodes with `JSON_INVALID_UTF8_SUBSTITUTE`, so each of those bytes left as U+FFFD and the reference was gone before anything reached the caller. `record:list --fields singleSRC` returned a row of replacement characters where a UUID belonged, and `RecordListTool` handed that on to the browser chat unchanged.

  v0.2.15 fixed this by putting `convertFileTreeFieldsToUuid()` on `AbstractModelReadCommand`. `RecordListCommand` extends `AbstractReadCommand` directly and so never inherited it — the fix landed one class too low. It has been moved up to `AbstractReadCommand`, which is where it belonged from the start: the conversion is driven by the DCA and takes a table name and a row, not a `Model`. `RecordListCommand` now maps its result rows through it; every existing caller is unaffected, since `AbstractModelReadCommand` still reaches the method by inheritance.

  Worth naming plainly: this is the one read command that accepts an **arbitrary** table, which makes it the likeliest of all of them to be pointed at an unfamiliar `fileTree` field — and it was the only one left without the guard.

  Found on 2026-08-31 while checking whether the CLI could reach the generic table commands at all.

- **`contao:record:list` answered an unknown `--fields` column with a stack trace.** The command validates three things against the DCA — the sort clause, the filters and the requested columns — and each raises the same `\InvalidArgumentException`. Two of the three calls were wrapped in a catch that turned it into `{"status":"error","message":...}`. `resolveColumns()` was not, so `--fields gibtsnicht` escaped as an uncaught PHP exception: nothing on stdout, a stack trace on stderr, exit 1 — while the identical mistake in `--order` or `--filter` answered properly.

  Also felt in the browser chat, where `RecordListTool` passes the failure straight through: a model that guessed a column name got a stack trace where it needed the sentence "unknown column".

  Found live against a test install on 2026-08-31, not in review — two guarded calls in a row read as if all three were covered.

- **`contao:record:list` died on any table without an `id` or a `tstamp`.** Three separate places assumed every `tl_*` table has both: the allow-list merged them in by decree (with a comment claiming they "always exist"), the `--order` option carried the literal default `id DESC`, and the fallback column list was `['id', 'tstamp']`.

  On a stock 5.7 install that is wrong five times over. `tl_opt_in_related`, `tl_newsletter_deny_list`, `tl_search_index` and `tl_search_term` have no `tstamp`; `tl_search_index` — a join table of `pid`, `termId` and `relevance` — has no `id` either. A missing `tstamp` reached the SELECT as `SQLSTATE[42S22]: Unknown column`; a missing `id` left the column list empty and produced `SELECT  FROM …`, a syntax error. Both exited **255** with a stack trace rather than answering.

  Fixed at all three: the allow-list is intersected with the real schema, the sort default falls back to the table's first real column, and an empty column list falls back to every readable column. `tl_search_index` now lists `pid, termId, relevance` ordered by `pid DESC`.

  The schema comparison is case-insensitive on purpose, keeping the DCA's spelling. Doctrine's `listTableColumns()` lowercases its array keys, so `singleSRC` comes back as `singlesrc` — a plain `array_intersect` would have dropped every camelCase column Contao has (`singleSRC`, `multiSRC`, `pageTitle`, `cspReportLog`) while appearing to work, since most core columns are lowercase anyway.

### Changed

- **A table with no curated column list now describes itself the way Contao does.** The default arm of `defaultColumns()` returned `['id', 'tstamp']` for everything outside the ten curated tables, which made the zero-argument call useless in exactly the case the command exists for: `record:list tl_image_size` answered "I do not know this table, show me what is in it" with two columns that say nothing, and finding a usable size meant a round of `dca:schema`, picking from some thirty fields, and asking again.

  It now uses `list.label.fields` — the column set of Contao's own back end list view, the DCA's answer to which fields identify a record. `tl_image_size` lists `id, name, tstamp`; `tl_module` lists `id, name, type, tstamp`.

  Chosen after measuring, not guessing: of the 29 non-curated tables with a DCA on a live 5.7 install, 22 declare `label.fields` and 7 declare nothing — and those 7 are `tl_search*`, `tl_version`, `tl_opt_in_related`, `tl_comments_notify`, `tl_newsletter_deny_list`, system tables with no back end list at all, where `id` and `tstamp` genuinely is the whole story. `list.sorting.fields` was considered as a middle tier and dropped: it fired on none of the 29, and it answers what to sort by rather than what a record is.

  The ten curated tables are untouched — their hand-picked lists are richer than their label fields (`tl_page` labels with `title` alone, while the curated list carries pid, alias, type and published as well). `RecordListTool` in contao-ai-backend-bundle allows exactly those ten tables and no others, so the browser chat cannot reach the changed branch at all; verified by comparing both lists, not assumed.

### Notes

Suite: 231 tests, 23 skipped, 0 errors (210 before). The fileTree tests were run against the pre-fix source first and failed there with `Call to undefined method RecordListCommand::convertFileTreeFieldsToUuid()`, so they pin the regression rather than merely accompanying the fix.

Verified live against a 5.7.12 install. Every one of the 43 `tl_*` tables was listed: 39 answered `ok`, 4 returned the structured "DCA not found" error they should, and **none** exited 255 — five did before. `singleSRC` came back as a UUID through both the new schema comparison and the fileTree conversion, and all ten curated tables returned their previous column sets unchanged.

## v0.2.16 - 2026-08-29

### Changed

- **The write path sits behind an interface.** `RecordWriterInterface` now owns persisting a record and everything a write owes the audit trail; `ModelWriter` is implementation A and writes through Contao's model layer exactly as the commands did inline. This was a move, not a rewrite — the response shapes, the version ordering, the cascade order and the `tl_undo` payload are unchanged, and verified as such against a live install.

  The reason is not the Contao 6.1 core operations, though they are why it is an *interface*. It is that the rules around a write accreted one bugfix at a time and each landed somewhere else: the cascade and undo entry in v0.2.8/v0.2.9, the `''`-in-a-tinyint normalisation in v0.2.10, the system log in v0.2.11–v0.2.13, the `tinyint` read in v0.2.15. None of them could be tested without booting a container, which is why several shipped uncovered — the cascade order and the `tl_undo.pid` correction among them.

  They can be now. `ModelWriterTest` exercises the delete path against a mocked `Connection` and cascade collector: children before parents, one undo row for the whole set, filed under the deleting user, nothing filed when the snapshot is empty. Five tests where there were none.

  Both abstract bases delegate, so every `contao:*:update` and `contao:*:delete` command inherits the seam without a change of its own. `AbstractWriteCommand::writer()` throws a sentence naming `setRecordWriter()` if it is missing, because no existing test reached the write path — they all assert error paths that return earlier — and the first one that does should not meet a null.

  **Create commands stay outside for now.** They build their model with per-entity knowledge (which fields a page create takes, how an alias is derived) before saving, and that knowledge belongs in the command rather than the writer; their version snapshot already goes through the shared `createVersion()` helper. Folding them in means giving the interface a field-based `insert()`, which is worth doing when the create commands are next touched.

  Signatures speak tables, ids and fields rather than `Contao\Model` objects on purpose: an implementation built on core operations would never see a model, so a model-shaped interface would look swappable without being it.

### Notes

Suite: `vendor/bin/phpunit` → 210 tests, 18 skipped, 0 errors (202 before).

Verified live: clone → update → delete on a test install. Update wrote version 2 under the right operator; delete returned the unchanged payload (`cascade`, `rowsTotal: 3`), wrote one `tl_undo` row with `affectedRows: 3` and `pid: 0` for a CLI deletion, and left no orphans behind.

## v0.2.15 - 2026-08-29

### Fixed

- **`record:clone` reduced every `tinyint` value to 1.** Cloning a tour page turned walking time 2 into 1, difficulty 2 into 1, severity 4 into 1, driving time 2 into 1 and the participant cap 4 into 1 — practically the whole fact table — while the command reported `status: ok`. `0` survived, and `smallint` and `varchar` were untouched, which is what pointed at a boolean cast rather than at the clone logic.

  The value was already gone before the cloner saw it. Doctrine DBAL maps every MySQL `tinyint` to `Types::BOOLEAN` regardless of the declared length (`AbstractMySQLPlatform`: `'tinyint' => Types::BOOLEAN`), Contao's `Model::convertToPhpValue()` honours that mapping on read, and so `Model::row()` hands out `true` for a stored `2`. Assigning that to the clone and saving it writes `1` back, because `Database\Statement::set()` binds a PHP boolean as `ParameterType::BOOLEAN`. A normal `page:update --set stunden=2` was never affected — it passes a string, which never meets the cast.

  All four cloners now read the source record with a plain `SELECT *` and copy the stored values. That is how stock Contao avoids the same trap: the back end never reads a record through the model layer — `DataContainer::preloadCurrentRecords()` runs a plain `SELECT *` over DBAL, and `DC_Table::copy()` takes its source row from there via `getCurrentRecord()`, which is why copying inside the back end was never affected. The shared `CopiesSourceRows` trait covers the nine copy loops across `PageCloner`, `NewsArchiveCloner`, `CalendarCloner` and `FaqCategoryCloner`; a test fails if a cloner goes back to `Model::row()`.

  **What this deliberately does not change:** read commands still report a `tinyint` column as `true`/`false`. Mapping `tinyint` to boolean is Contao's convention rather than a defect — its own DCA uses `smallint(5)` and `int(10)` for numbers and reserves `tinyint` for flags, and the cast table is generated from the DCA (`ContaoCacheWarmer::generateColumnCastTypes()`), not from the live schema. Returning raw values instead would make `published` come back as `"1"` rather than `true`, putting this bundle at odds with the back end and every other consumer. A column that stores a number and is declared `tinyint` is mis-declared, and the fix belongs in the DCA that owns it. A cloner is the exception, because it has to reproduce stored values whatever their type.

- **`record:clone` discarded `--modifications` it did not accept, in silence.** Anything outside a cloner's allow-list was dropped with no error, no warning and nothing in the response. A call carrying `{"published":"","hide":"1"}` therefore produced two pages that inherited `published = 1` from their source and were publicly reachable for about three minutes; the command had said `status: ok`.

  Every cloner response now carries `ignored_modifications`, always present and empty on a clean call, so a caller can tell an applied override from a discarded one. The `--modifications` description names each cloner's accepted fields and states that `alias` is never taken from there — it is always regenerated from the title with a uniqueness suffix, which was equally undocumented.

- **`published` and `hide` are now accepted modifications for `tl_page`.** "Clone it, but do not put it live yet" is the normal case, not the exception: the cloned content elements are already forced to `invisible = '1'` for exactly that reason, and the page itself had no counterpart. `hide` covers the case after that — a clone that will be published but has to stay out of the navigation, such as a test variant or a page reachable only by its URL; without it that took a second write, with the page sitting in the menu in between.

  Both are normalised to `'0'`/`'1'` first. Callers write `""` for "off", and an empty string in a `tinyint NOT NULL` column is the write that had to be fixed in v0.2.10 — extending the allow-list without normalising would have reintroduced it.

  The allow-list now follows a stated rule rather than accreting field by field: **an override is accepted when it controls whether and where the clone becomes visible.** `protected` and `groups` are deliberately excluded — those are access control, and a `protected: ""` on the clone of a protected page would expose its content, which is what the allow-list exists to prevent. A test pins the policy in both directions, including the invariant that every normalised flag is also whitelisted.

- **Read commands returned binary file references, destroying them on the way out.** A `fileTree` column holds Contao's 16-byte binary UUID. The read path handed it straight to `json_encode()`, and `JSON_INVALID_UTF8_SUBSTITUTE` replaced every byte that is not valid UTF-8 with U+FFFD, so `contao:page:read 98` answered with `"navigationImage": "GW<FFFD>V+8<0x11><FFFD><FFFD><NUL><NUL>(<FFFD>T"`. The reference was gone before anything left the server; the database and the transport both had it intact.

  A caller could therefore not tell which file a record points at. It also crashed contao-ai-cli on a cp1252 console, which is how it was found.

  `AbstractModelReadCommand::convertFileTreeFieldsToUuid()` is the missing inverse of `AbstractWriteCommand::convertFileTreeFields()`, which has converted UUID strings to binary on write since v0.2.1. DCA-driven, handles `multiple` fields, and leaves anything that is not a binary UUID alone.

### Added

- **`--ids` on every entity update command.** `contao:page:update --ids=39,40,41 --set max_teiln=4` applies the same values to many records in one invocation. Inherited by all `AbstractModelUpdateCommand` subclasses, so page, news, event, FAQ, article and content all have it.

  Setting one field on 174 pages previously took about four minutes: 1.4 s per record, of which 0.67 s was establishing the SSH connection and nothing else. The only alternative was `bridge rewrite`, an LLM loop that bills API tokens to write a constant.

  **Each record keeps its own version and its own system-log entry** — the audit trail is the reason writes go through the console at all, and it is not what was slow. Only the connection is shared. The response is a summary (`total`, `succeeded`, `failed`, `ids`, `errors`) and the exit code is non-zero when any record failed, so a shell loop notices. A malformed entry in the list is named and refused rather than skipped: a silent skip is exactly how the bulk run of 2026-08-29 managed to look successful while changing one record out of 174.

  The ID argument stays a single-record path with an unchanged response shape; giving both is an error, as is giving neither.

### Notes

Suite: `vendor/bin/phpunit` → 202 tests, 18 skipped, 0 errors (158 before).

## v0.2.14 - 2026-08-25

### Added

- **`--operator` on `contao:version:restore` and `contao:template:write`.** Both wrote the shell user into `tl_log.username` no matter who was acting. That was harmless before v0.2.11, when nothing reached the system log at all; now it is a wrong name in the audit trail. It also failed quietly rather than loudly: contao-ai-backend-bundle only passes `--operator` to commands whose definition declares it (`AbstractCoreCommandTool::runCommand()`), so a missing option costs the attribution without raising anything.

  The option, its description and the `$_SERVER['USER']` fallback now live in one `OperatorOptionTrait`, shared by the four commands that do not extend `AbstractWriteCommand` — `version:create` and `record:clone` had their own copies of the same three lines.

- **A label for `CLI` in the back end's origin filter.** `tl_log.source` is rendered through a reference lookup; Contao ships `BE` and `FE`, so a console write showed as the raw string. The bundle now carries `contao/languages/{en,de}/tl_log.xlf` with `tl_log.CLI` → *Command line* / *Kommandozeile*. This is the first `contao/` resource directory in the bundle; nothing else was needed to make Contao find it.

### Changed

- **The test suite is no longer red by design.** Every plain `vendor/bin/phpunit` reported 17 errors - all of them `RuntimeException: The Symfony container is not available` from tests that resolve a Contao model. That is a documented consequence of running without `CONTAO_ROOT` (see `tests/bootstrap.php`), but a suite that is red on purpose is a suite nobody reads: the next real regression arrives as error number 18 and looks like the weather.

  They are now skipped instead, with the reason and the fix in the skip message. Same information, and a failure stays visible as a failure. The guard checks whether a container is actually there rather than whether `CONTAO_ROOT` is set, because the container is what the test needs; who supplied it is beside the point.

  Before skipping anything, all 17 behaviours were verified against a live container, so the skip is not hiding a defect.

  `README.md` now documents both test modes; they were only described in a docblock nobody opens.

### Notes

Verified live on Contao 5.7.11: `version:create`, `version:restore` and `template:write` with `--operator webwerkwien` all land in `tl_log` under that name (`CLI`/`GENERAL` and `CLI`/`FILES` respectively), and `System::loadLanguageFile('tl_log')` resolves `CLI` to *Command line* in English and *Kommandozeile* in German. Test template, version rows and the patched vendor files were removed afterwards.

Suite: `vendor/bin/phpunit` → 158 tests, 18 skipped, 0 errors. `CONTAO_ROOT=… vendor/bin/phpunit` → no skips.

## v0.2.13 - 2026-08-25

### Fixed

- **Macro-bridge writes were labelled `FE`.** v0.2.12 decided the `tl_log.source` column on "is a request running", which is the wrong question. contao-ai-cli's macro bridge posts to `/_ai_cli/macro`, deliberately routed outside `/contao/*` so the back-end firewall cannot redirect it - so it carries no backend scope, and `ContaoTableProcessor` filed the CLI under `FE`.

  The test is now `ScopeMatcher::isBackendRequest()`, the same one the processor itself uses: a real back-end request is handed back to Contao (`BE`), everything else - console and bridge alike - is `CLI`.

  Verified on the console; the back-end and bridge branches are read from Contao's `ContaoTableProcessor` and the bridge route definition rather than measured.

## v0.2.12 - 2026-08-25

### Fixed

- **Back-end writes were labelled `CLI`.** v0.2.11 hard-coded `tl_log.source` to `CLI`, which is right for a console write and wrong for everything else: contao-ai-backend-bundle runs these very commands in-process during a back-end request (`AbstractCoreCommandTool::runCommand()`), so an editor's change through the AI chat was attributed to the console.

  `SystemLog` now sets the source only when there is no request. With it left null, `ContaoTableProcessor` reads the request and fills in `BE` (or `FE`) itself - the same answer Contao gives for any other back-end write. Shipped in v0.2.11 and found the same day, before any back-end write had been made against it.

## v0.2.11 - 2026-08-25

### Added

- **Every write now appears in Contao's system log (`tl_log`).** Until this release nothing this bundle did was visible in the back end, and the log line it *did* write went nowhere: `outputSuccess()` logged through the plain app-channel `LoggerInterface`, and in a Managed Edition that channel reaches neither `tl_log` nor `var/logs`. Measured on a production site after weeks of CLI edits - `grep -r "contao-ai-core-bundle audit" var/logs/` returned zero hits, and `tl_log` held nothing but cron and back-end entries. Two graphics had been swapped through the CLI; the only trace left was a `tstamp` on `tl_files`.

  Reaching `tl_log` takes two things, and the bundle had neither. The entry must go through a `contao.*` Monolog channel, which Contao's `LoggerChannelPass` decorates with its `SystemLogger`; and the log context must carry a `ContaoContext`, because `ContaoTableHandler::handle()` returns early without one. The new `Service\SystemLog` does both. It passes its own context rather than letting the decorator build one, because `ContaoTableProcessor` only fills what is still null - and on the console there is no request and no security token, so an auto-built context would land as `username = N/A`, `source = FE`.

  Covered: every command through `AbstractWriteCommand::outputSuccess()` (create, update, delete, publish), plus `contao:template:write`, `contao:version:create`, `contao:version:restore` and `contao:record:clone`, which have their own success paths.

  `contao:version:create` is a special case worth naming: Contao writes *"Version X of record ... has been created"* whenever its own `Versions` class runs, but `VersionManager` writes `tl_version` directly and so bypassed that line entirely.

- **`tl_log.source` is `CLI` for console writes.** Contao itself only ever writes `BE` or `FE`. A console write is neither, and calling it `BE` would be a lie in the one column an operator reads to ask "did a person do this?". The back-end filter falls back to the raw value when no translation exists, so it shows as `CLI` rather than a translated label.

  Attribution follows what the command was given: `--operator` when the backend bundle passes a Contao username, otherwise the shell user. `action` is `GENERAL` for records and `FILES` for the file, folder and template commands.

### Notes

Failed commands are still not logged. A rejected `--set` changed nothing, and logging validation errors would bury the real writes.

Verified live on Contao 5.7.11 (Managed Edition): `page:create`, `page:update`, `page:publish`, `page:delete`, `version:create` and `folder:create` each produced exactly one `tl_log` row with `source=CLI`, the right action, the operator as username and the command name in `func`. Test records and the test folder were removed afterwards, `tl_version` and `tl_undo` left with no orphans.

## v0.2.10 - 2026-08-24

### Fixed

- **`page:publish <id> unpublish` and `comment:publish <id> unpublish` threw instead of unpublishing.** Both wrote `''` into `published`, which is `tinyint(4) NOT NULL`. An empty string is not a falsy tinyint, it is an invalid one: a lax server silently coerces it to 0, a server running `STRICT_ALL_TABLES` / `TRADITIONAL` raises `SQLSTATE[22007] Incorrect integer value: ''`. Publishing worked, so only one of the two directions was ever broken - and only on strict servers.

  The value now comes from a shared `AbstractWriteCommand::booleanFlag()`, so the reason is written down once rather than duplicated into the next command that needs a boolean column.

  Same shape as the `invisible = ''` bug fixed in v0.2.1, and the answer to the follow-up left open in [#1](https://github.com/webwerkwien/contao-ai-core-bundle/issues/1): the create commands do **not** share the pattern, these two publish commands did.

### Notes

Verified live on Contao 5.7.11 with `sql_mode=TRADITIONAL,STRICT_ALL_TABLES`: both directions return `{"status":"ok","published":true|false}` and the column holds 1 or 0.

## v0.2.9 - 2026-08-24

### Fixed

- **`tl_undo.pid` carried the record's author instead of the user who deleted it.** That column means "the backend user who performed the deletion" - `DC_Table::delete()` writes `BackendUser::getInstance()->id` - and the undo module filters on it for everyone who is not an admin. Writing the author put the entry into the undo list of whoever happened to write the record, and left it at 0 on the tables that have no `author` column (`tl_page`, `tl_content`), where only admins could then see it.

  `tl_undo.pid` is now the Contao user behind `--operator`, which the backend bundle already passes on every write command. A plain CLI deletion has no backend user and stays at 0, so it remains admin-visible - that is the honest value rather than an arbitrary attribution.

### Changed

- `resolveAuthorId()` is now a thin wrapper over the new `resolveOperatorUserId(int $fallback)`. The fallback differs by purpose: `author` on create keeps id=1 so a byline is never empty, while `tl_undo.pid` falls back to 0.

### Notes

Verified live on Contao 5.7.11: deleting without `--operator` writes `pid=0`, deleting with `--operator j.wilson` writes that user's id, so the entry appears in their own undo list.

## v0.2.8 — 2026-08-24

### Fixed

- **Deleting a record left its children behind as orphans.** `AbstractModelDeleteCommand` called `Contao\Model::delete()`, which is a plain single-row `DELETE`. Deleting a page therefore removed the page and left its articles and their content elements in the database — invisible in the back end, and unreachable by anything Contao offers: there are no foreign keys in the schema, and the Automator's fourteen tasks are all `purge*` for caches, logs and tokens. Nothing reclaims an orphan, because Contao prevents them instead, in `DC_Table::delete()`.

  `DC_Table` cannot be called from the console — it runs `denyAccessUnlessGranted(new DeleteAction(...))` and there is no security token on the CLI. The new `RecordCascadeCollector` therefore mirrors its collection step: the whole descendant subtree for tree tables (`tl_page`), recursive descent through the DCA `ctable`, `dynamicPtable` children matched on pid *and* ptable, and `doNotDeleteRecords` honoured. Nested content elements — `tl_content` has `ctable => ['tl_content']` — are included, which the old code missed even when deleting a single element.

- The `tl_undo` snapshot now covers **every** collected row rather than only the record named on the command line, in the `[table => [row, …]]` shape `DC_Table::undo()` expects. Restoring brings the children back with the parent, as it does in the back end.

### Changed

- The delete commands report what they took: `"cascade": {"tl_page": 2, "tl_article": 2, "tl_content": 3}, "rowsTotal": 7`. Silence about a cascade is how the orphans went unnoticed.
- Rows are removed children-first, so an interrupted run cannot leave a parent pointing at rows that are already gone.

### Added

- 10 unit tests for the traversal in `tests/Service/RecordCascadeCollectorTest.php`, against DCA and row fixtures rather than a live installation: subtree collection, recursive `ctable` descent, `dynamicPtable` discrimination, nested content, `doNotDeleteRecords`, self-referencing rows, and root-table ordering.

### Notes

Verified live on Contao 5.7.11: a disposable page tree (2 pages, 2 articles, 3 content elements of which one nested) deleted in one call — `rowsTotal: 7`, zero orphans afterwards, and the single `tl_undo` entry replayed all seven rows back into place across all three tables.

Affects the browser chat as much as the console: `page_delete` and its siblings in contao-ai-backend-bundle run through these same commands.

`tl_undo.pid` still carries the record's author rather than the deleting user, so on tables without an `author` column the entry is admin-only. Known and accepted — see the project notes.

## v0.2.7 — 2026-08-13

### Fixed

- Removed the `imagedestroy()` calls in `FileProcessCommand`. GD resources became `GdImage` objects in PHP 8.0 and are released by the garbage collector, so the calls have had no effect since then — and **PHP 8.5 deprecates the function**, which made the bundle emit deprecation notices on a version its own `php: ^8.2` constraint covers.

### Notes

The suite was run across the supported PHP range for the first time, against Contao 5.3.49 (the declared `^5.3` minimum): PHP 8.2, 8.4 and 8.5 all give 126 tests and 200 assertions, with 8.5 now free of deprecations. PHP 8.3 could not be covered locally — winget's package returns a 404 for the archived build.

Testing against 8.2 requires Composer to resolve for that platform (`composer config platform.php 8.2.33 && composer update`); dependencies resolved under 8.4 pull in packages requiring `>= 8.4.1`, and the generated `platform_check.php` then aborts. Note that Contao 5.7.9+ itself requires `php: ^8.3`, so PHP 8.2 is only reachable in combination with Contao 5.3–5.6.

## v0.2.6 — 2026-08-13

### Changed

- `user:update` and `member:update` validate the requested fields **before** loading the record. A rejected field no longer depends on whether the account happens to exist, and the guard stays reachable without a database — which is what made it testable at all. Visible difference: updating a non-existent user with a disallowed field now reports `Field(s) not allowed` instead of `User not found`.

### Added

- Tests for both allow-lists, replacing four placeholders that had been marked incomplete since the first release. They pin the privilege-escalation guard: `admin`, `password`, `secret` and `useTwoFactor` must stay out of the backend-user list, `password`, `activation` and `secret` out of the member list, while ordinary profile administration remains available.
- `CHANGELOG.md` — reconstructed from the git history back to v0.1.0.

## v0.2.5 — 2026-08-13

### Changed

- `export-ignore` keeps development-only files out of the distributed package — `composer require` no longer pulls the test suite and PHPUnit configuration into the consumer's `vendor/`.
- Corrected the `.gitattributes` header comment: `text=auto eol=lf` normalises line endings but does **not** strip a UTF-8 BOM, which the previous wording implied. That gap is exactly how a BOM survived in `tests/Service/VersionManagerTest.php` even after v0.1.2 removed the BOMs from the command files.

### Added

- The PHPUnit suite runs for the first time. It never had: the BOM above made one test file unparsable, the Contao plugin bundles were missing from `require-dev`, and `$GLOBALS['TL_MODELS']` was never seeded.
- `tests/bootstrap.php` has two modes — plain unit run, or `CONTAO_ROOT=/path/to/contao vendor/bin/phpunit` which boots that installation's kernel so the model-driven tests get a real container and database. Against Contao 5.7.11: 114 tests, 183 assertions, 0 errors.

## v0.2.4 — 2026-08-13

### Fixed

- **`tl_news.headline` was stored as a serialized `{value, unit}` payload instead of plain text.** That column is the news *title* — Contao's DCA declares `inputType 'text'` on a `varchar(255)` and renders the value verbatim, so affected entries showed the raw `a:2:{s:5:"value";…}` string in the back end listing, the RSS feed and the front end. Only `tl_content.headline` is a genuine `inputUnit` field. The bug survived because `NewsReadCommand` deserialized the same payload on the way out, so reading a record back looked correct. See [#3](https://github.com/webwerkwien/contao-ai-core-bundle/issues/3).
- `news:create` no longer accepts `--unit`; `tl_news` has no headline level.

### Added

- `contao:news:repair-headlines [--dry-run]` unpacks legacy rows written by earlier versions. Idempotent — values that do not deserialize into an array with a `value` key are left untouched, so it is safe on clean installations and on repeated runs.

## v0.2.3 — 2026-07-26

### Added

- Generic `inputUnit` support: a `<field>_unit` companion (`--set headline_unit=h1`) or a JSON value (`--set 'headline={"unit":"h1","value":"…"}'`) sets the heading level, validated against the DCA options. Covers `content:create`/`content:update` and the news commands, replacing the previously hard-coded per-command logic. Prompted by a question in the Contao community forum.
- Canonical serialisation order `['value' => …, 'unit' => …]`, byte-identical to Contao's own SQL default.

## v0.2.2 — 2026-07-25

### Fixed

- `file:process` guards against `getimagesize()` returning `false` for corrupt uploads, which previously produced "Trying to access array offset on bool" warnings that could contaminate the JSON output.

## v0.2.1 — 2026-07-25

### Added

- `contao:file:write` returns the file `uuid` and syncs new files into the DBAFS directly (`Dbafs::addResource`) — a separate `contao:filesync` is no longer needed.
- String→binary UUID conversion for all `fileTree` DCA fields (`singleSRC`, `multiSRC`, …), so CLI-created records resolve file references exactly like back end ones.

### Fixed

- `content:create` wrote `invisible = ''`, which aborted every insert under MariaDB's strict SQL mode on an integer column.

## v0.2.0 — 2026-05-03

### Added

- Phase 9 macro layer: `contao:record:clone` with plugin-aware cloner registry (news archive, calendar, FAQ category, page trees including articles and nested content), and `contao:record:list` — a table-agnostic listing with filters, pagination and curated default columns.
- Ownership fields are filled on create (`author`, `cuser`) instead of defaulting to the admin.

### Fixed

- Stable ordering in record/news listings (secondary `id DESC` tie-breaker, full timestamp on `news.date`) so "the newest entry" stops being undefined on same-day ties.
- `tl_content.headline` is serialised as an input-unit payload on both create and update.

## v0.1.4 — 2026-04-26

### Added

- Delete commands snapshot the record into `tl_undo` first, so deletions made through the bundle are recoverable via the standard back end "restore" flow.

## v0.1.3 — 2026-04-26

### Added

- `user:update` accepts the `username` field.
- `.gitattributes` enforcing LF line endings.

## v0.1.2 — 2026-04-26

### Fixed

- Removed UTF-8 BOMs from the PHP command files.
- Added the missing `Command` use statement to ten commands.

## v0.1.1 — 2026-04-24

### Changed

- Removed `roave/security-advisories` from `require-dev` — a library must not constrain its consumers' dependency resolution.
- Package metadata (authors, keywords, homepage, support links); internal planning documents removed from the public repository.

## v0.1.0 — 2026-04-24

Initial public release (beta, MIT).

### Added

- Console commands for agent-driven CRUD across `tl_user`, `tl_member`, `tl_page`, `tl_article`, `tl_content`, `tl_news`, `tl_calendar_events`, `tl_faq`, `tl_comments`, plus file and folder operations.
- Read commands returning full records as JSON, including layout inheritance resolution in `page:read`.
- Template commands enforcing Contao's naming and placement conventions (`override` / `partial` / `variant`).
- Version history commands (`version:list`, `read`, `restore`, `create`) built on direct Doctrine DBAL access — `Contao\Versions` requires an authenticated back end session and is unusable from the CLI.
- Write commands create `tl_version` snapshots so CLI edits appear in the record history like back end edits.
