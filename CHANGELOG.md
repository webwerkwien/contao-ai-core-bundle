# Changelog

All notable changes to this project are documented here. The project adheres to [Semantic Versioning](https://semver.org/) (within the pre-1.0 reservations).

This file was reconstructed from the git history on 2026-08-13, so entries before that date describe what the tags contain rather than what was written at release time.

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
