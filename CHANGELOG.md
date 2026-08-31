# Changelog

All notable changes to this project are documented here. The project adheres to [Semantic Versioning](https://semver.org/) (within the pre-1.0 reservations).

This file was reconstructed from the git history on 2026-08-13, so entries before that date describe what the tags contain rather than what was written at release time.

## v0.2.27 - 2026-08-31

### Fixed

- **`--set` with a field that is not a column reported success and changed nothing.** `--set gibtesnicht=1` answered `{"status":"ok","updated":["gibtesnicht"]}`. Nothing was written: `Model::save()` filters `arrModified` against `Database::getFieldNames()` and drops what is not a column — but `ModelWriter::update()` reported back the names it had been *given*.

  🎯 **A silent no-op that reports success is the failure this project keeps hunting.** Same shape as the bulk run of 2026-08-29 (174 IDs in, one record changed, "0 failed") and the pipx no-op of v0.4.3. A wrong answer that looks like an answer is worse than an error, because nobody goes looking. A typo in a field name read as a successful change.

  Now refused, with the field and the table named. Refusing rather than reporting truthfully, because the read side already does: `contao:record:list` validates `--fields`, `--filter` and `--order` against the DCA and refuses the rest — guessing a column name is safe there *because* it fails loudly. Writing should not be the looser of the two.

  ⚠️ **Checked against the real columns, not the DCA.** They are not the same set — `tl_layout.rows` is declared in the DCA and does not exist in the database. What decides whether a write lands is the column list, so the check asks the very function `Model::save()` uses to make that decision.

  A database that cannot answer is not treated as a failed check: the write then fails on its own terms and says what actually went wrong, rather than turning an infrastructure problem into a confusing validation message.

  **Behaviour change:** a script passing a field name that this bundle used to swallow now fails with exit 1. That is the point — it never did anything — but it will surface as a new failure rather than silently.

## v0.2.26 - 2026-08-31

### Changed

- **An uncaught exception now leaves the same JSON error every other failure leaves.** Deliberate failures answer `{"status":"error","message":…,"code":1}` and exit 1. An exception that got past a command answered a PHP stack trace on stdout and exit 255 — not a worse message but *no* message, in a shape nothing here expects. The caller is a script or an agent parsing JSON.

  `JsonErrorBoundary` catches `\Throwable`, not just DBAL: a caller cannot act differently on a `TypeError` than on a `DriverException` — both mean "this command did not do the thing" — and a boundary that lists the exceptions it knows about only holds until the next one nobody listed.

  🎯 **The objection to catching everything is real: it hides the stack trace you want while developing.** So the boundary has a vent — at `-vvv` (`VERBOSITY_DEBUG`) the exception is rethrown untouched and Symfony renders it as before. Nothing in the CLI passes `-vvv`, so the vent costs the normal path nothing.

  Exit code is **1**, the same as every other error here, not 255. The exit code answers one question — "did it work" — and telling a database error from a usage error is what the message is for. The message keeps the exception's short class name (`DriverException: …`), which is the one piece that says which layer failed.

  Applied to **every** command, not only those extending the two base classes: the seven that extend Symfony's `Command` directly (`RecordClone`, `TemplateList`, `TemplateWrite`, `Version*`) use the trait themselves. A boundary that holds for most commands is not a boundary, and which is which is invisible from the outside — `JsonErrorBoundaryTest` fails by name if a command falls outside it.

  Verified live against c5: a 600-character value into `tl_newsletter.alias` (varchar 255) now answers `DriverException: … Data too long for column 'alias'` with exit 1, where it previously produced a stack trace and exit 255.

## v0.2.25 - 2026-08-31

### Fixed

- **`--set field=` crashed on boolean and integer columns** ([#24](https://github.com/webwerkwien/contao-ai-core-bundle/issues/24)). `--set teaser=` cleared a text column and always worked; `--set addFile=` died with *Incorrect integer value* and an uncaught DBAL exception — a stack trace and exit 255 out of a command whose whole contract is a JSON result. Same syntax, two outcomes, decided by a column type the caller cannot see. Affects create and update on every table: 159 `boolean` and 124 `int … NOT NULL default 0` columns in a stock Contao 5.7.12.

  🎯 **Contao already answers this, and it publishes the answer.** `Widget::getEmptyValueByFieldType($sql)` is `public static`, takes the DCA `sql` definition and returns the empty value that column can hold — `null` where nullable, `0` for the integer family, `false` for `boolean`, `''` otherwise. `DC_Table::save()` calls it for exactly this purpose.

  Refusing the empty value was the first instinct and would have been wrong: it would have made this bundle stricter than the back end at a place where Contao has a considered answer and hands it over without a `protected` to reach around. Because the mapping returns `''` for a NOT NULL string column, every empty value can run through it and text fields come out unchanged — no special-casing.

  `convertEmptyValues()` runs last inside `convertFields()`, since the three conversions before it leave empty values alone on purpose.

## v0.2.24 - 2026-08-31

### Added

- **The newsletter can be written.** Nine commands over the module's three tables — `contao:newsletter-channel:*`, `contao:newsletter:*` and `contao:newsletter-recipient:*`, each with create, update and delete. It was the last entry in the back end menu that could be read and not written.

  Registered only where `contao/newsletter-bundle` is installed: excluded from auto-discovery via `Newsletter*.php` and loaded from `config/services_newsletter.yaml` behind `class_exists(NewsletterChannelModel::class)`.

  ⚠️ The `News*.php` exclude pattern already matches `Newsletter*.php` — `fnmatch` does not care about the word boundary. The explicit line is there anyway, because these commands hang off the newsletter bundle rather than the news bundle and would fall through silently the day that pattern is narrowed.

  Nothing new was needed for the field rules. `tl_newsletter.files` is mandatory only inside the `addFile` subpalette, and `missingMandatoryFields()` already covered both levels; `files` is a `fileTree` with `multiple` and `jumpTo` a `pageTree`, both handled by `convertFields()`.

### Changed

- **`newsletter send` does not exist, and `sent` cannot be written.** Contao's send routine is browser-driven — each cycle ends with a JavaScript timer that loads the next batch — so there is nothing to hand through to a console command, and a mail that has gone out cannot be recalled. Sending stays with a person in the back end.

  🎯 **A refusal only in the CLI would be a detour sign, not a boundary.** `NewsletterSendGuard` therefore sits on the write path itself and refuses `sent` and `date` on create, update and the bulk `--ids` form alike. Setting `sent=1` sends nothing: it marks the newsletter as sent *and publishes it in the front end archive*, because `NewsletterModel::findSentByPid()` filters on exactly that flag. It is the one substitute an agent would reach for, and it is worse than doing nothing.

- **Recipients follow Contao's own CSV import, not the front end subscribe module.** Valid address, no duplicate in the channel, and not on the channel's deny list — the three rules of `Newsletter::importRecipients()`. Its permission check is dropped on purpose: it gates a back end user against their module rights, and whoever reaches a console command already has shell access.

  `active` defaults to off and `addedOn` stays empty, so the back end labels the row "added manually" rather than presenting it as an opt-in it never was.

  🎯 **The deny list carries more weight than it looks.** Both opt-out paths write the entry and then *delete* the recipient row (`BlockRecipientListener`, `ModuleUnsubscribe`), so the duplicate check finds nothing for someone who unsubscribed — the row is gone. The deny list is the only thing left that remembers it, and it is not administratively removable: `tl_newsletter_deny_list` has no `dataContainer` and no back end module. Only `ModuleSubscribe::activateRecipient()` clears an entry, after a confirmed opt-in (Contao #4999) — the person who opted out is the only one who can lift it.

- **Address fields are validated.** `tl_newsletter_channel.sender`, `tl_newsletter.sender` and `tl_newsletter_recipients.email` carry `rgxp => 'email'`, which `DC_Table` enforces through the widget and this write path does not. Checked explicitly for now; the write layer validates no `rgxp` at all, which is its own gap and its own release.

### Fixed

- **`active` was written as an empty string.** `tl_newsletter_recipients.active` is declared `['type' => 'boolean']` — a real tinyint, not the `char(1)` older DCAs use for flags — and MySQL in strict mode rejects `''` for it with *Incorrect integer value*. Only the default path died; passing `--active` wrote `'1'` and worked, which is what hid it. Found on the first live write, not by the suite: mocked tests cannot see a column type.

## v0.2.23 - 2026-08-31

### Added

- **The form generator can be written.** `contao:form:*` and `contao:form-field:*`, each with create, read, update and delete, plus `contao:form-field:types`. It was the last content module readable but not writable — `form list` and `form fields` had existed for a while, and neither half could be created.

  `tl_form_field` is `tl_module` in miniature: **21 types, a palette each**, and mandatory fields that apply only to some of them. A `submit` needs `slabel`, a `select` needs `name` and `options`, a `captcha` needs nothing at all. Reading `eval.mandatory` alone would demand all of them at once — the trap the module command walked into first, answered the same way through `missingMandatoryFields()`, so no new logic was needed for it.

  `tl_form`'s own requirements are conditional too: `recipient` and `subject` are mandatory, but they live in the `sendViaEmail` subpalette. A form that only stores its values needs neither.

- **`optionWizard` fields take a short form.**

  ```
  --set options="mrs=Mrs.|mr=Mr."     value and label
  --set options="red|green|blue"      label doubles as the value
  ```

  🎯 **This is the one invented shorthand in the bundle, and the reason is that the field is mandatory.** For `tl_settings.allowedAttributes` — optional, rarely touched — the answer is still "pass Contao's serialized form". Here `select`, `radio` and `checkbox` cannot be created without options, so the same answer would leave three of the 21 types uncreatable and the gap this command exists to close still open.

  Verified against live data: a generated `options` value came out structurally identical to the demo install's own, down to the key order. `size` on a textarea came out **byte-identical** to Contao's — checked rather than assumed, because that is where the `pagemounts` int question came from earlier today.

- **The alias is generated and then checked**, the way `tl_form::generateAlias` does it: a purely numeric alias is refused because Contao cannot tell it apart from a record ID, and a duplicate is refused because it does not fail at request time — it routes to whichever record the query returns first.

- **New fields are appended 128 apart**, the gap Contao's back end leaves between neighbours so a later drag can land between them without renumbering the form. Same rule as image size variants.

## v0.2.22 - 2026-08-31

### Added

- **The three parent tables can be created.** `contao:news-archive:*`, `contao:calendar:*` and `contao:faq-category:*`, each with create, read, update and delete.

  `news:create`, `event:create` and `faq:create` have always taken a `--pid`, and the record that `pid` pointed at could not be created. **The child worked, the parent did not** — so the first news item on a fresh install still meant opening the back end. The same gap in the same shape, three times over.

  Only `--title` is a dedicated option on each. **What else is required is read from the DCA**, not hard-coded, and the three tables exercise three different corners of that:

  - `tl_news_archive` and `tl_calendar` need `jumpTo` — the page that renders a single item; without one, every link the module generates goes nowhere. It is mandatory in the default palette, so it is always required.
  - `groups` becomes required only once `protected=1` opens its subpalette. Demanding it always would refuse every public archive.
  - `tl_faq_category` has no `protected` subpalette at all, needs `headline` instead, and offers `jumpTo` without requiring it. `title` is the back end label and `headline` the heading on the page — nothing derives one from the other.

  Nothing in the code knows that FAQ categories are different; the DCA says so.

- **The palette and subpalette rules are now one check.** `AbstractWriteCommand::missingMandatoryFields()` replaces the two that had grown separately — the palette rule in `ModuleCreateCommand` (v0.2.18) and the subpalette rule in `MemberGroupCreateCommand` (v0.2.19), the latter with a note that a third caller would be the moment to unify them rather than guess at the shape early. The parent tables were that caller. Both existing commands delegate to it; the change was invisible to all 313 tests that existed before it.

### Fixed

- **Commands for optional Contao bundles are excluded by filename, and `Calendar*` did not match `Event*`.** `services.yaml` auto-discovers `../src` and excludes the commands that depend on `news-bundle`, `calendar-bundle`, `faq-bundle` or `comments-bundle`, which `ContaoAiCoreBundle::loadExtension()` then registers only when that bundle is installed. The calendar exclusion reads `Event*.php`, because that is what its existing commands are called — so four new `Calendar*Command` classes went straight through it and would have been registered on **every** installation, including ones with no calendar bundle.

  Caught before release by the live check: of twelve new commands, exactly four were registered, and those four were the wrong ones. The other eight were correctly excluded and then registered nowhere — the harmless direction to fail in, but neither half is visible from reading the YAML.

  `PluginCommandRegistrationTest` now asserts both directions: a command referencing a plugin-only model must be excluded from auto-discovery **and** listed in the matching `services_*.yaml`. Verified by mutation — removing the exclusion turns the test red and names the file.

## v0.2.21 - 2026-08-31

### Fixed

- **`contao:dca:schema` answered with array indices instead of the option values.** The line applied `array_keys($def['options'])` unconditionally, and that is right for exactly one of Contao's two forms:

  ```php
  array('de' => 'Deutsch')                 // assoc — the key IS the value
  array('map_default', 'map_always')       // list  — the key is 0, 1, …
  ```

  Contao's own DCAs use the list form almost everywhere, so almost every answer was wrong. Confirmed live before fixing: `tl_page.sitemap` came back as `[0, 1, 2]` against a DCA declaring `map_default, map_always, map_never`.

  🎯 **Two reasons it survived this long, and both are the point.** It *reads* correctly — looking at that line you picture the associative form, and there it is right. And the wrong answer **looks like an answer**: `tl_content` declares `array(1, …, 12)`, whose keys are `0..11`, so the reply is plausible and off by one throughout. A caller building `--set` from it is rejected by the DCA and then goes looking in the wrong field.

  That is this project's recurring silent-failure shape with a turn of the screw: this one does not stay quiet, it answers. `dca:schema` exists so an agent knows what it may set instead of guessing — the same reason `contao:user-group:options` exists — and it was handing out values nobody could set.

  Option groups are flattened too: `array('Group' => array('a', 'b'))` is an optgroup, and the group name is a label rather than something a caller can set.

### Added

- **`optionsSource` per field in `contao:dca:schema`** — `static`, `callback`, `foreignKey` or `null`. A field with an `options_callback` or a `foreignKey` has options; they are simply not in the DCA array, and several callbacks need a live DataContainer this command does not have. Reporting a bare `null` for those made "this field takes any value" and "the values exist but not here" look identical — the same confusion the wrong `options` caused, one step further along. On a live install `tl_page.type` and `tl_page.layout` now say `callback` instead of appearing to have no options at all.

  Found by the parallel session working on the wienerwandern booking module, which hit it on a table of its own and checked `tl_page` to rule out its own DCA. Verified across nine tables on the test install: 61 fields carry options, and the single list that still looks like indices is `tl_module.news_startDay`, which genuinely declares `array(0, …, 6)` for the days of the week.

  The CLI needs no release for this — it passes the server's answer through unchanged.

## v0.2.20 - 2026-08-31

### Added

- **Deleted records can be restored.** `contao:undo:restore` and `contao:undo:read`. `contao:version:restore` has existed since Phase 2 and answers "this record changed and I want it as it was"; its counterpart never existed. Every delete in this bundle has written a `tl_undo` row since v0.2.8 — for a cascade, one row covering the parent and everything under it — so the safety net was being filled and never emptied.

  Follows `DC_Table::undo()` step for step, because the steps are not decoration: the DCA is loaded before the table is touched (an `onundo_callback` only exists once it is), columns the table no longer has are dropped rather than failing the insert, `onundo_callback` runs per row, the `tl_undo` entry is deleted **only** if every insert succeeded, and the log line is Contao's own `Undone <query>` on Contao's own channel.

  **The column-drop is not theoretical.** On the test install, an existing entry for a theme cascade carried `tl_layout.rows` — a field the DCA declares and the database does not have, confirmed through `record:list` (`unknown column: rows`). Without the intersect that restore would have failed over a field nobody misses. Verified end to end on exactly that entry: four rows across four tables and three levels, and the restored image-size variant pointed at the restored size again, which is what restoring with the original IDs is for.

  `contao:undo:read` decodes the payload — tables, row counts, IDs — plus the two things that decide whether the restore can work at all: `idsTaken`, because Contao re-inserts with the original ID and an occupied one makes the insert fail, and `droppedColumns`, because a silently shortened record is worth knowing about before rather than after.

- **The global settings can be read and changed.** `contao:settings:read` and `contao:settings:update` — the last back end entry with no table behind it. `tl_settings` is a `DC_File`; the values live in `system/config/localconfig.php` as `$GLOBALS['TL_CONFIG'][…]`, which is why `record:list tl_settings` answers "No readable columns" correctly and why a wrapper could not have covered this.

  **The write does not trust a destructor.** `Config::persist()` only marks the instance modified; the file is written by `Config::__destruct()`. So `save()` is called explicitly and `localconfig.php` is read back afterwards — a configuration change that reported success and wrote nothing is precisely the failure shape this project keeps finding.

  **An unknown key is refused, and nothing is written.** `Config::persist()` writes any key given to it, and nothing ever reads it back or complains. A typo would put a dead variable permanently into a file nobody opens by hand. Contao is protected by its form only offering real fields; here the check has to be explicit. A mandatory setting cannot be emptied either — an empty `dateFormat` breaks every date on the site.

  **`read` separates `value` from `persisted`.** The effective value may come from the bundle default rather than from anything an administrator chose; on the test install `resultsPerPage` reads `30` and is not persisted at all. Reporting only the value would make "somebody set this" and "this is the default and will move when the default moves" indistinguishable.

  Values that already match are skipped and the file is not touched, compared loosely because `Config::get()` answers `30` where `--set` always arrives as `"30"`. The log line matches Contao's `DC_File::save()` — same channel, same "from … to …" wording, same omission of the value for a password field.

## v0.2.19 - 2026-08-31

### Fixed

- **The create commands were not converting the fields they stored.** Both conversions on the write path arrived one command at a time — `convertFileTreeFields()` with v0.2.15, `convertMultipleFields()` with v0.2.18 — and both were wired into the update path and then into whichever create command was being written that day. Counted on 2026-08-31: of the **eleven** create commands that accept `--set`, **four** converted fileTree values and exactly **one** converted multi-value fields.

  So `news create --set singleSRC=<uuid>` wrote the UUID as a string into a binary column — the same destruction v0.2.15 fixed on the read side, still live on the other path. `page create --set groups=1,2` wrote a bare string where Contao stores a serialized array. Both reported success.

  There is now one entry point, `convertFields()`, and `CreateCommandConversionTest` asserts that every create command applying `--set` fields calls it. Forgetting is a failing test rather than a silent wrong value. The test also asserts that its own scan found commands, because a scan over nothing passes just as quietly as a scan over everything.

- **`cud` and `chmod` were written as bare strings.** `tl_user_group.cud` and `tl_page.chmod` store a flat serialized list exactly like a multi-value field, but carry no `eval.multiple` — the widget itself is the list, so Contao never sets the flag. Verified against live data: `cud` is `a:60:{i:0;s:21:"tl_form_field::create";…}`, `chmod` is `a:9:{i:0;s:2:"u1";…}`. Both are now recognised by input type.

- **Page mounts were stored as strings where Contao stores integers.** `PageTree::validator()` runs `array_map('\intval', …)`, so the back end writes `a:1:{i:0;i:1;}` and this bundle wrote `a:1:{i:0;s:1:"1";}`. Every consumer compares loosely — `array_intersect` in `BackendAccessVoter`, `in_array(…, false)` for groups — so nothing behaved differently, but a record this bundle writes should be indistinguishable from one the back end wrote.

  The rule is `pageTree` and nothing else, measured rather than assumed: of every widget in core-bundle exactly two cast to int, and `Picker` only does so on its single-value branch — a comma-separated Picker list keeps its strings.

### Added

- **The permission tables can be written.** `contao:user-group:create|read|update|delete` and `contao:member-group:create|read|update|delete`. `tl_user_group` is what decides which back end modules an editor sees, which page and file mounts they reach, which fields they may edit and which tables they may create in; `tl_member_group` is what protected front end content points at. Both were readable through `record:list` and writable nowhere, so "create a back end user" had always been half an answer.

  Only `--name` is required, which mirrors the DCA: `name` is the single mandatory field and every permission defaults to "not granted". A group with nothing but a name is valid and harmless — the right default for a permission record.

  Neither delete cascades, because neither table declares a `ctable`. What stays behind is a dangling reference: `tl_user.groups` and the `groups` field of protected content keep the dead ID, and Contao does not clean those up in the back end either. The commands match that rather than inventing a cleanup the back end does not perform.

- **`contao:user-group:options` — because a wrong permission value is silent.** Everywhere else in this bundle a bad value fails loudly against the DCA. Not here: a permission field accepts any string, stores it, and grants nothing. `--set modules=pages` (plural, wrong) reports success and leaves the group without page access, with no error anywhere to explain it. Guessing does not even self-correct, which is what `contao:module:types` could still rely on.

  Everything comes from the DCA and the registries Contao itself reads, so an extension's back end module or content element appears without a change here — verified on a live install, where the `ai_chat` module of contao-ai-backend-bundle showed up alongside Contao's own. Modules flagged `disablePermissionChecks` are dropped, as `tl_user_group::getModules()` drops them.

  `cud` and `alexf` are per-table and sit behind `--table`: `cud` reads `config.permissions`, which Contao's own `CudPermissionListener` fills, and `alexf` uses `DataContainer::isFieldExcluded()` — the same test the back end applies.

- **A mandatory field is only mandatory where Contao shows it — now at subpalette level too.** `tl_member_group.jumpTo` is marked mandatory in the DCA but lives in the `redirect` subpalette, and DC_Table demands it only once that selector is on. `MemberGroupCreateCommand` reads `subpalettes` rather than hard-coding `redirect => jumpTo`, so an extension adding a subpalette to that table is covered without a change here. Same principle as the palette rule in `ModuleCreateCommand`, one DCA level down.

- **A DCA `unique` value is checked before creating.** `tl_user_group.name` and `tl_member_group.name` carry `eval.unique` and DC_Table refuses a duplicate — but there is no unique index behind it, so a write path that goes around DC_Table drops the rule with it. Create now refuses a taken name. The generic update path does not check `unique` yet; doing so DCA-wide would also start rejecting renames that succeed today (`tl_page.alias` among them), which is a change of its own.

## v0.2.18 - 2026-08-31

### Fixed

- **A multi-value field was written as a bare string, and read back as nothing.** A DCA field with `eval.multiple` holds a serialized array — `tl_module.news_archives` is `a:1:{i:0;s:1:"1";}`. `--set news_archives=1` wrote `1`, and nothing complained: `StringUtil::deserialize()` hands a non-array straight back, so Contao iterated a string and found no archives. The module was configured and did nothing.

  `convertMultipleFields()` now serializes them, DCA-driven like the fileTree and inputUnit conversions beside it, and runs on every update rather than only for modules — the same shape applies to `tl_page.groups`, `tl_module.pages`, `cal_calendar`, `faq_categories`, `nl_channels`. A comma-separated value becomes a list; a value already in Contao's format is left alone, so re-running is a no-op and a caller passing the serialized form still works. Empty values stay empty, because an unset multiple is `''` in the database and not an empty array.

  Verified live: a created module's `news_archives` came out byte-identical to the demo install's own (`a:1:{i:0;s:1:"1";}`), and `--set news_archives=1,2` on update produced `a:2:{i:0;s:1:"1";i:1;s:1:"2";}`.

### Added

- **Image sizes can be written, not only read.** `contao:image-size:create|read|update|delete` and `contao:image-size-item:create|read|update|delete` — the first entity of the theme layer to get commands of its own. Until now `tl_image_size` was reachable for reading through `record:list` and not at all for writing, so creating a size meant the back end by hand.

  Update, delete and read are six-line subclasses: they inherit versioning, the system log, `--ids` and the cascade from the abstract bases, and per-entity code there would only be somewhere for those to drift apart. Verified on a live install — create wrote version 1, update version 2, both attributed to the operator, two `tl_log` rows with source `CLI`.

  **The cascade is now counted rather than assumed.** `tl_image_size.ctable` declares `tl_image_size_item` and `RecordCascadeCollector` follows it, but with no item rows anywhere that had never been exercised. Two variants were created, the size deleted: `cascade: {tl_image_size: 1, tl_image_size_item: 2}`, `rowsTotal: 3`, **zero orphans**, one `tl_undo` row for the whole set. The same assumption went unchecked on 2026-08-24 and left orphans behind, which is why it was worth building the item commands to be able to test it.

  Two pieces of entity knowledge sit in the create commands, and only there:

  - **`--pid` is required and is a theme ID.** `tl_image_size.ptable` is `tl_theme`; a size belonging to no theme is not something Contao has.
  - **New variants are appended, 128 apart.** That is the gap Contao's own back end leaves between neighbours so a later drag can land between them without renumbering.

  `preserveMetadataFields` is marked mandatory in the DCA and stands NULL in every row the back end writes — the requirement only bites in the form, once `preserveMetadata` asks for a field list. Nothing is invented for it, so a created row matches what the back end produces.

- **Themes and layouts too.** `contao:theme:create|read|update|delete` and `contao:layout:create|update|delete` — `contao:layout:read` already existed and was, until today, the theme layer's only command of any kind.

  Three findings from checking rather than assuming, each of which would have been a quiet defect:

  - **`tl_theme.author` is free text, not a user reference.** It is a `text` field in the DCA and Contao's own demo theme carries "Joe Ray Gregory, Sascha Müller, Felix Pfeiffer, …" in it — a credit line. Every other create command here fills its `author` column with `resolveAuthorId()`; doing that would have put a number where a name belongs.

  - **`tl_layout.template` gets no default, deliberately.** The DCA marks it mandatory and offers no default; its options come from a callback that needs a live DataContainer, because a legacy layout is offered the `fe_*` PHP template group while a modern one gets the `page/layout` Twig templates found on disk (`ThemeLayoutListener::getTemplateOptions`). A create command has no DataContainer, so it cannot resolve that list — and defaulting to `fe_page` because the demo install uses it would be inventing an answer only the caller can give.

  - **`LayoutUpdateCommand` overrides `defaultInputUnit()` to `px`.** Five columns here are `inputUnit` and all five offer `px % em rem vw vh`. The inherited default is `h2`, the headline unit from `tl_content`, which is meaningless for a layout width. Without the override the validation falls through to the first option — which happens to be `px`, the right answer for the wrong reason, and one a reordering of Contao's list would quietly break.

  Verified live: an update with a plain number kept the record's existing unit, and `--set width=90 --set width_unit=vw` changed it, with the companion key never reaching the database as a column. Both wrote `a:2:{s:5:"value";…;s:4:"unit";…}` — the same key order Contao's own `InputUnit` widget produces, whose form renders `name="field[value]"` before `name="field[unit]"`. (A demo-fixture row carries the reverse order; `unserialize` is order-agnostic and every reader accesses by key, so neither is wrong.)

  **The theme cascade was counted three levels deep:** deleting a theme with one layout, one image size and one variant reported `{tl_theme: 1, tl_layout: 1, tl_image_size: 1, tl_image_size_item: 1}`, `rowsTotal: 4`, and left the neighbouring demo theme's 5 layouts, 3 sizes and 41 modules untouched.

- **Modules complete the theme layer.** `contao:module:create|read|update|delete`, plus `contao:module:types`.

  `tl_module` has 113 fields and twelve of them carry `mandatory`, which reads like a command nobody could call. It is not, because **a mandatory field applies only to the module types whose palette contains it** — which is not an interpretation but how `DC_Table` validates: it walks the active palette, and a field outside it is never asked for. On a stock 5.7 that means 21 of the 45 types need nothing beyond a name and 24 need something more.

  So the requirement is **computed from the DCA at runtime** rather than kept as a table in the command. A second copy of that mapping would be a second thing to maintain, and it would silently miss the module types a third-party extension registers — those arrive with their own palettes and are covered for free.

  Neither failure mode guesses. An unknown `--type` is refused with the valid types listed; a type whose palette wants fields the caller did not supply is refused with those fields named. `contao:module:types` gives both up front, because "provoke a failure to discover what is allowed" is a poor contract for a tool meant to be driven by an agent.

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
