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

- **Agent-neutral.** `AGENTS.md` is the one guide for every coding agent; `CLAUDE.md`
  only imports it. Nothing here may depend on one coding agent: no agent-specific instructions,
  paths or file names in the guide, the tests or the code — the `CLAUDE.md` shim that
  only imports this file is the one exception.
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
| `refuseInvalidOptions()` | a value not in the field's declared `options` list |
| `refuseTakenUniqueValues()` | a duplicate in a `eval.unique` field |
| `refuseUnstructuredValues()` | **from v0.12.0** — a value that is not a serialized array for a field whose widget stores one (`moduleWizard`, `sectionWizard`, `rowWizard`, `imageSize`, … — 14 input types). Runs **after** the conversions, so a short form that becomes an array (`options="red\|green"`) passes |

All six answer with `{"status":"error"}` and exit 1, and nothing is written.

> 🔴 **Why the sixth exists.** Up to v0.11.0 `layout update 25 --set modules=66`
> answered `ok` and stored the string `66` — the layout lost its module list and
> rendered nothing, with no error anywhere. Measured on c5 on 2026-09-16. For a
> wizard field pass Contao's own form:
> `modules=a:1:{i:0;a:3:{s:3:"mod";s:2:"66";s:3:"col";s:6:"header";s:6:"enable";s:1:"1";}}`.
> `eval.multiple` is not covered on purpose — with `eval.csv` such a field stores a
> comma-separated string.

> ⚠️ **Booleans take `1` or `0` — not `true`, `yes` or `on`.** From v0.7.0 those
> are refused with a message naming the field. This is stricter than it looks
> necessary, and the reason is Contao 6: it casts a value into the column's
> declared type instead of letting the database refuse it, so
> `--set published=vielleicht` became `published=true` and reported success.
> Measured on 2026-09-05 — on Contao 5.7.13 the same input was an error, so this
> is a Contao-6-only silent failure. An empty value (`--set published=`) is
> accepted and means 0, because that is what an unchecked checkbox submits.

### `eval.rgxp` applies to the parts the widget checks (v0.14.0)

`refuseInvalidValues()` never holds a rule against a serialized whole or a comma list.
`rgxpParts()` hands it what Contao's widget would validate:

| widget | rgxp applies to |
|---|---|
| `inputUnit` | the value — the unit goes against `options` |
| `imageSize` | width `[0]` and height `[1]` — `[2]` is a size ID or mode |
| `timePeriod` | the value — the unit goes against `options` |
| `text` with `eval.multiple` | every entry, comma list or serialized |

> 🔴 Up to v0.13.0 only `inputUnit` was split. No image size could be set on an element
> (a bare `6` is refused as unstructured, the serialized triple failed `natural`), and
> `playerSize`, `mooClasses`, `contextLength` and the mandatory `tl_form_field.size` could
> not be written in any form. See `RgxpPartsTest`.

### Page URLs follow Contao's back-end rules (v0.15.0)

`PageUrlGuard` keeps page create, update and clone to what `PageUrlListener` enforces in
the back end. Each of the three writes **in a transaction and checks afterwards**; a
refusal rolls the write back, version and log entry included.

| rule | how |
|---|---|
| no second root with the same `dns` and `urlPrefix` (an empty prefix is a prefix) | Contao's own query from `validateUrlPrefix()`, verbatim — identical in 5.3, 5.7, 6.0 |
| no second page at the same URL | **Contao's `generateAlias()` is called**, not rebuilt — it compares whole URLs through the router |

**Similar aliases are a hint, not a refusal** (v0.20.0). Contao's back end saves a second
page with alias `index` under the same root and shows only *"the following pages have a
similar alias that may conflict"* (`PageRoutingListener::generateRouteConflicts()`). Page
create and update now report the same as `routeConflicts: [{id, title, alias, path}]` —
`PageUrlGuard::routeConflicts()` applies Contao's rule (same domain, routable, same static
URL prefix plus suffix) through `selectRouteConflicts()`, which is testable without Contao.
Up to v0.19.0 the bundle saved such a page silently (Nr. 48, checked in the back end on
2026-09-17). A bulk `page update --ids` reports them per record,
`routeConflicts: {"<id>": [...]}`. The check runs after the write has committed and never
fails it: anything it throws means no hint, not an error. `generateAlias()` does not catch the `index` case because `RouteProvider` gives
`index` pages an extra `.root` route that its URL match does not see.

`validateUrlPrefix()` itself cannot be called: it goes through `getCurrentRecord()`, which
asks the permission voters and fails without a back-end user. `generateAlias()` needs only
`$dc->id` and reads the stored record — hence check-after-write.

> ⚠️ **`generateAlias()` detaches the model it looks up.** `findWithDetails()` →
> `loadDetails()` removes that instance from the registry and forbids saving it. Holding
> the same `PageModel` and calling `save()` afterwards fails with *"The model instance has
> been detached"*. Load a fresh instance with `PageModel::findByPk()` — once detached, the
> registry no longer holds it — and save through that. v0.15.0 wrote through the
> connection instead; it worked but left the model layer, and was replaced in v0.15.1.

**Cloning a root** accepts `language`, `urlPrefix`, `urlSuffix`, `fallback` and `dns` as
modifications, so the root takes its new language in one step. The pages below keep
Contao's copy titles (`… (Kopie)`, alias `…-kopie`) and are renamed afterwards. A root
cloned onto its source's domain and prefix is refused. Cloned pages get their alias from
`generateAlias()`, as a back-end copy does (`tl_page.alias` carries `doNotCopy`); the cloned
root goes behind its last sibling (`Service\Sorting`).

### Generated aliases are Contao's (v0.17.0)

A create without an alias gets the one Contao's own `save_callback` of the alias field
makes — from the title, with the language and `validAliasCharacters` of the page the
record belongs to, unique as Contao checks it. `Über uns` on a German root is `ueber-uns`.

- **Pages:** `PageCreateCommand` writes an empty alias and asks `PageUrlGuard::generateAlias()`
  after the save, in the same transaction — `PageUrlListener::generateAlias()` loads the page
  by its id. Saved through a fresh `findByPk()` instance (see the detached-model note above).
- **Everything else** (articles, news, events, newsletters, any extension with a
  `generateAlias` callback): `resolveAlias($table, '', $from, record: [...])` calls the
  callback before the write through `Service\Dca\ContaoAlias`, with a `RecordDataContainer`
  that answers `activeRecord` with the fields to be created and `id` with 0. Pass the fields
  the callback reads — `title`/`headline`/`subject` and `pid`.
- A callback that throws leaves the old slug (`StringUtil::generateAlias()`) and says so:
  `aliasWarning` in the answer, a warning in the log. **Never catch it silently** — in the
  first live test a missing import landed there and looked exactly like success.

> 🔴 Up to v0.16.0 every create used `StringUtil::generateAlias()`: `über-uns` where Contao
> makes `ueber-uns`, and on a root set to `0-9a-z` aliases the installation forbids. A clone
> of the same page got Contao's `uber-uns-kopie`. Found on the Contao 6.0 test installation.

> 🔴 Up to v0.14.0: `page update --set urlPrefix=en` made a second root `conpai.eu/en`,
> `--set alias=packages` a second page at `/en/packages`, and `record clone` of a root left
> two roots on the same domain and prefix — all `ok`. Measured on c5 on 2026-09-16, see
> `PageUrlGuardTest`.

### New records go behind their last sibling (v0.14.0)

A create in a table with `pid` and `sorting` passes `'sorting' => $this->nextSorting($table,
$pid[, $ptable])` to `preparedFields()`: `MAX(sorting)` of the siblings plus 128, Contao's
step. Siblings are the same `pid`, for tl_content also the same `ptable`. As the command's
own value it loses to a `--set sorting=` of the caller. `maxSorting()` is the lookup alone
and may be overridden by a command that has a Doctrine connection.

> 🔴 Up to v0.13.0 pages, articles, content elements and FAQs were created with
> `sorting = 0` — the order on a page was the database's tie-break. Form fields and image
> size items did it right with private copies. `SortingOnCreateTest` now lists all six;
> **a new create command for a sorted table belongs in that list.**

### `inputUnit` fields: each half has its own rule (v0.11.0)

`tl_content.headline`, `tl_layout.width` and the other `inputUnit` fields store a
pair, `serialize(['value' => …, 'unit' => …])`. The caller gives the value with
`--set headline=…` and the unit with `--set headline_unit=h1` (or both as JSON,
`{"unit":"h1","value":"…"}`). Without a unit, an update keeps the stored one and a
create takes the unit in the field's **SQL default** (`tl_module.headline`: `h2`),
or the first option when the default has none (`tl_layout.width`: `px`).

**Every write command converts, from v0.12.0.** The conversion runs first in
`convertFields()`. Until v0.11.0 only `content create` and `layout create` did it —
`module create --set headline=…` stored the bare string and answered `ok`. A value
that already is a `{value, unit}` pair is left alone.

| half | checked against |
|---|---|
| value | `eval.rgxp` — `width=abc` is refused, `width=90` passes |
| unit | the field's `options` — for `inputUnit` they list **units**, not values |

**A unit the caller names and the DCA does not list is refused**, with
`headline_unit=h9` in the message. A stored or default unit that is not listed
still falls back to the default, so old data cannot make a record unwritable.
The option list is read as Contao reads it (`optionValues()`): the values of a list,
the **keys** of an associative array (v0.17.0; before, `['h1' => 'Heading 1']` refused `h1`).

**A unit on its own changes the unit and keeps the value** (v0.17.0): `content update 5
--set headline_unit=h1` rewrites the stored pair with `h1`. Up to v0.16.0 the companion key
was consumed, nothing was written and the answer was `ok` with `updated: []`.

> 🔴 **v0.2.28 to v0.10.0 could not write these fields at all.** The conversion
> into the pair ran before the checks, so `refuseInvalidValues()` held the whole
> serialized string against `rgxp` (since v0.2.28: `width=90 --set width_unit=vw`
> failed with *expected: digit*), and `refuseInvalidOptions()` held it against
> the unit list (since v0.9.0: **no headline could be set on any content
> element**). And up to v0.10.0 an unknown unit was silently swapped for the
> default and answered `ok`. Found in the ConpAI 1.0 acceptance test on
> 2026-09-16; see `InputUnitValidationTest`.

## What `contao:dca:schema` answers about options

Three fields, and they answer different questions:

| field | meaning |
|---|---|
| `options` | the values a caller may set — or `null` when they are not in the DCA array |
| `optionsSource` | where they come from: `static`, `callback`, `foreignKey`, or `null` for a field that takes any value |
| `optionsTarget` | **from v0.8.1** — for a `foreignKey`, the table the values live in: `{"table": "tl_consho_shop", "labelField": "title"}` |

**`options` are values, not labels — also for `eval.isAssociative`** (v0.21.1). A
list declared `isAssociative` stores its index: Contao's `tl_page.useSSL` declares
`array('http://', 'https://')` and answers `["0", "1"]`, so `--set useSSL=1` is the way
to set https. Up to v0.21.0 the labels came back and `useSSL=1` was refused (Nr. 53,
live on web.werk.wien). As in Contao's widget, the flag applies to the top level only;
an optgroup decides by its own keys. In 5.7.13 `useSSL` is the only such field.

`optionsTarget` is `null` for everything else, **including a `foreignKey` whose
label is computed** — Contao's own `tl_member` declares
`CONCAT(firstname," ",lastname)`, where there is no column to name. In that case
`optionsSource` still says `foreignKey`, so a caller learns the values are
elsewhere either way.

> ⚠️ **`--set` enforces `static` only.** From v0.9.0 a value outside a declared
> `options` list is refused; `foreignKey` and `options_callback` are not checked.
> Measured on 2026-09-11 against a stock 5.7.13 with all five optional bundles,
> **279 of 1183 fields declare an options source**:
>
> | source | fields | enforced | why |
> |---|---|---|---|
> | `options` | 94 | **yes** | the list is in the DCA, complete and closed |
> | `foreignKey` | 79 | no | Contao itself produces dangling references — see below |
> | `options_callback` | 106 | no | needs a live `DataContainer` this path has not, and may answer differently per record |
>
> **The `foreignKey` exclusion is deliberate.** Of 55 scalar, checkable
> foreign-key fields on that installation, one was broken: `tl_news.jumpTo`
> points at page 13 in all 27 rows that set it, and page 13 does not exist — in
> a field declared `mandatory`. Contao allowed the page to be deleted and cleans
> up nothing. Refusing that on write while the framework creates it on delete
> would make this CLI stricter than Contao.
>
> `optionsTarget` is what lets a caller run the check itself where it wants one.

### The sentence that bounds all five rules

> **What is checked is what the DCA *declares* — not what an extension checks in
> a `save_callback`.**

This write path goes through Contao's model layer, not `DC_Table`, so callbacks
do not run — with one narrow exception, cache tags (next section). That is deliberate: the model layer is what produces the
`tl_version`, `tl_undo` and system-log entries this bundle exists for. But it
means the five rules cover the declared surface and nothing beyond it.

Reported from the Consho bundle on 2026-09-11, and worth quoting because it
shows both halves of the cost:

- `page update --set conshoPathTemplate={category}/{alias}` was **stored**.
  The back end refuses it — `{category}` is not a valid placeholder — but that
  check lives in a `save_callback`. Over the CLI it answered `"status": "ok"`.
- The same callback **records the old product URLs so they can be redirected**.
  Changed through `--set`, no recording happens: the old addresses break, and
  nothing fails while it happens.

So a rule in a callback is invisible here, and a *side effect* in a callback is
invisible here too — and the second is the one that leaves no trace.

⚠️ **For an agent this is an instruction, not trivia.** Where an extension is
known to validate or to act in a callback, drive that field through the
extension's own command (`ext run`, see `#[AiContract]`) or through the back
end — not through `--set`. Where no such command exists, the extension has not
said how it wants to be operated, and `--set` is a guess.

### The one kind of callback that does run: cache tags (v0.10.0)

Every write invalidates the HTTP cache the way `DC_Table` does. Before v0.10.0
nothing did — `Model::save()` does not invalidate on its own — and cached pages
kept their old state, `/sitemap.xml` (`s-maxage=2592000`) for up to thirty days.

The tags are `DataContainer::invalidateCacheTags()`'s, identical in Contao 5.3,
5.7 and 6.0: the record's tag, its parent's tag (or the table tag), and whatever
the table's **`oninvalidate_cache_tags_callback`s** add. Those callbacks are the
only source of `contao.sitemap.<root>` — in tl_page, tl_news, tl_calendar and in
every extension with addresses of its own in the sitemap. So they run; they are
declared in the DCA and only hand back a list of tags. `save_callback`,
`onsubmit_callback` and `ondelete_callback` still do not (decided 2026-09-13).

What a caller sees in a successful answer:

| key | present | meaning |
|---|---|---|
| `cacheTags` | when anything was invalidated | the tags that went out |
| `cacheWarnings` | **only** when something failed | that part of the cache may still be stale — `cache clear` removes it |

Where it happens, and where it differs from the back end:

- **Delete:** tags are collected **before** the rows go, as `DC_Table::delete()`
  does — the sitemap callbacks look the record up. Only the root record.
- **`undo restore`: every restored row is invalidated. This is better than the
  back end, on purpose.** `DC_Table::undo()` invalidates on itself, whose table
  is `tl_undo` at that point, so restored records are never invalidated there.
  An extension that works around this with an `onundo_callback` needs that
  workaround for the back end still.
- **Creates, `record clone`, `version restore`, `--set`, `publish`:** after the
  write.
- **tl_content and other `dynamicPtable` tables:** the parent tag comes from the
  record's `ptable` column, as `DC_Table::findPtable()` does — the DCA has no
  ptable for them. A nested element's parent is `tl_content` itself.
- The callbacks get a `DC_Table` built without its constructor (which needs a
  session and a request), carrying `id`, `table` and `activeRecord` — what
  Contao's own callbacks read. One that needs a request, a user or
  `getCurrentRecord()` fails, is named in `cacheWarnings`, and does not stop the
  others.
- Contao 5.3 has no `contao.cache.tag_manager`; `fos_http_cache.cache_manager`
  is used there, as 5.3's own `DataContainer` does.
- **Not covered:** file writes (`tl_files`), and anything written around this
  bundle (raw SQL). For those, `cache clear` is still the way.

## Deleting files: as the back end, but not while used (v0.18.0, v0.19.0)

`contao:file:delete --path files/… [--force]` follows `DC_Folder::delete()` of 5.7.13 and 6.0.0:
`Files::rrdir()` plus the web-dir symlink for a folder, `Files::delete()` for a file, the script
cache for css/js (`purgeCache()`), **then** `contao.filesystem.dbafs_manager->sync($path)`, which
compares `tl_files` with what is really left on disk (5.3's back end still calls
`Dbafs::deleteResource()`; the manager exists there as well). The sync also runs when the
resource could not be deleted completely — whatever did go leaves `tl_files` — and the answer is
then an error that says so. Every run that deleted something is logged, also when it answers
with an error (partial deletion, failed sync); one `tl_log` entry, the bundle's own, with the
operator. A dot entry directly below files/ (`files/.htaccess`, `files/.hidden`) cannot go through
the manager ("Dot path … is not allowed"), but a filesync records dot *folders* — its records are
removed with `Dbafs::deleteResource()` once nothing is left on disk.

**The path is canonicalised first** (`FileDeleteCommand::canonicalPath()`): `//`, `./` and
backslashes go, `..`, files/ itself and anything outside files/ are refused. **A symbolic link is
removed as a link** (`type: link`), never through to its target. A path that runs *through* a
linked folder is refused (`files/link/a.png`).

> 🔴 Up to v0.18.0 neither held — both reproduced on 6.0.0 (review, 2026-09-16).
> `files/rv/b//a.txt` found no `tl_files` record by that string: the UUID was never searched for,
> a used file went without `--force`, and its record stayed behind while the answer said
> `records: 1`. `files/rv/link` (→ `real`) emptied `real/`, kept the link, and answered that
> nothing had changed.

**Before deleting, `Service\Files\FileUsageFinder`** — the check Contao does not make. It searches
every `tl_` table with a DCA, except `tl_files`, `tl_version`, `tl_undo`, `tl_log`: `fileTree`
columns for the binary UUID (equality, or containment for serialized lists), and
`text`/`textarea`/`inputUnit` columns for the UUID as text (insert tags) and for the path. The
database preselects with `LOCATE`, one query per table and field for all resources (in chunks of
100); PHP then decides per resource — case-sensitively, and a path only as a whole path
(`FileUsageFinder::textNamesPath()`: `files/media` is not used by `files/media2/a.jpg`, `files/a.png`
not by `files/a.png.bak` — but a full stop ending a sentence after the path still counts). A hit
refuses the delete with `usages` (at most 50) unless `--force`; with `--force` the answer still
carries them. **`skippedTables`** appears when a table's DCA failed to load — without it, a clean
result would silently cover less than it claims. Not searched: templates and CSS on disk, values
outside a DCA.

**`records`** is the number of `tl_files` rows that are gone afterwards (counted before and after),
0 for a file the DBAFS never knew.

`undoable: false` is part of the answer on purpose: there is no `tl_undo` and no version for a
file. `WritePathTest` excuses the command — the record work is Contao's DBAFS. Like every file
command, it works on `files/`; a custom `contao.upload_path` is not supported.

> 🔴 Up to v0.17.0 there was no way to delete a file through the bundle (Nr. 46 of the ConpAI 1.0
> acceptance test). Verified live on c5 (a file used by four image elements refused, free files
> deleted with their records) and on 6.0.0 (folder with a used file refused, `--force` deleted
> three records; an insert tag `{{picture::…}}` in a text element found).

## Files: the installation's rules, and the DBAFS (v0.13.0)

**What goes into files/ is what the installation allows to be uploaded.** `UploadPolicy`
(trait, used by `FileWriteCommand` and `FileProcessCommand`) applies the rules of
`Contao\FileUpload::uploadTo()`: `maxFileSize` and `uploadTypes` first, then image dimensions
(`imageWidth`/`imageHeight`, refused when `contao.image.reject_large_uploads`, else resized
after the write), `FileUpload::sanitizeSvg()`. The one difference: PHP's
`upload_max_filesize` is not applied — it governs HTTP uploads, not a file that came over
SCP. `--allowed-types` on file process narrows `uploadTypes` and may not widen it.

> 🔴 Up to v0.12.0 `contao:file:write` checked none of it — any extension, a hard-coded
> 10 MB, no SVG sanitising — and `--allowed-types` replaced the system list. Measured on
> c5 on 2026-09-16, see `UploadPolicyTest`. `contao:file:write` reads bytes; the CLI's
> `file upload` sends binaries through it.

**Resizing computes the size itself and calls `File::resizeTo()`** (v0.17.0), not
`FileUpload::resizeUploadedImage()`. That method calls `Message::addInfo()`, which needs a
session the console lacks — it only got through because the language file was not loaded,
with PHP warnings in the log. And with only one limit set it scales to 0×0: `imageWidth=100`,
`imageHeight=0` turned a 600×20 PNG into a file of **0 bytes** (measured on c5; the back end
does the same). `resizeDimensions()` treats a limit below 1 as unset.

**A folder record comes from `Dbafs::addResource()`, never from `new FilesModel()`.**
`FolderCreateCommand` built one by hand until v0.12.0 and never set a UUID; a file written
into such a folder hung under no parent. An existing record without a UUID is now repaired
in place (`repairFolder()`): delete-and-re-add would make `addResource()` duplicate every
child, and a child's UUID may be referenced already. `contao:filesync` does **not** repair
it — it answered "No changes". See `FolderCreateDbafsTest`.

**`contao:folder:publish`** does the back end's five steps from the `protected` field of
tl_files: `isUnprotected()`, refuse when public only through a parent, `unprotect()` /
`protect()`, `Automator::generateSymlinks()`, the files-channel log line. Note that
`new Folder()` creates a missing directory — check before constructing it.

**`contao:file:move --path … --to <folder>`** (v0.23.0) is the back end's cut and paste
(`DC_Folder::cut()`): no circular move, no overwriting, `Files::rename()`, then
`contao.filesystem.dbafs_manager->sync($source, $destination)` — its move detection keeps
the UUIDs, on 5.3 as well as 5.7/6.0 (measured) — symlinks for a folder, and the files-channel
line with the operator. The answer carries `uuidsKept` (tl_files UUIDs before and after) and
`pathUsages`: `FileUsageFinder` run with the UUIDs left out, so it reports only text fields
naming the old path — the references a move breaks. Delete-and-write is no substitute: a new
UUID, and every element pointing at the old one renders nothing (Nr. 64).

**Reading:** every `binary(16)` column comes out as a UUID string
(`AbstractReadCommand::convertFileTreeFieldsToUuid()`), not only `fileTree` fields —
`tl_files.uuid` and `tl_files.pid` have no widget, and their raw bytes left as `null`.

## Cloning and creating pages — as the back end does (v0.22.0)

- **`record:clone` keeps each content element's visibility.** Pages and articles come out
  unpublished; a hidden element stays hidden, a visible one shows once its page and
  article are published — what Contao's "copy with subpages" does (`tl_content.invisible`
  has no `doNotCopy`, checked on c5 2026-09-17). Up to v0.21.1 every cloned element was
  forced invisible.
- **Only a root stores `tl_page.language`.** `contao:page:create --language` applies to
  `--type root`; any other page stores `''` and takes its root's language at runtime, as
  one created in the back end. An explicit `--set language=` is written as given, like any
  column (writes are checked per column, not per palette). A cloned subpage stores none; a
  cloned root keeps its own
  or the one from `--modifications`. (Contao's own copy empties it on the root too —
  `doNotCopy` — but a clone into another language sets it in the same call.)
- **Lines Contao's channels write for a command carry the CLI context.**
  `contao:folder:publish` logs *Regenerated the symlinks* and *Folder … has been
  published* as `CLI` with the operator; before, the console left them `FE` / `N/A`.
  `SystemLog::context()` gives the context for such a line.

## Deleting templates, clearing the cache — as the back end does (v0.21.0)

**`contao:template:delete --path templates/….html.twig`** mirrors the Template Studio of 5.7
(`DeleteOperation`, `AbstractDeleteVariantOperation`): delete the file, then
`TemplateCacheRefresher`, then — for `templates/content_element/<type>/<name>.html.twig` and
`templates/frontend_module/…`, the name deeper too (`…/text/a/b`, as `canExecute()` allows) —
`customTpl` of every `tl_content`/`tl_module` record naming the variant back to `''`, so it
renders the default template. Contao updates those rows through the connection without a
version or `tstamp`; the bundle sets both per record. Answer: `deleted`, `undoable: false`,
`migratedUsages: {table, field, ids}` when any, `templateCacheRefreshed`/`cacheWarning`.
**If resetting a record fails, the file is already gone:** the answer is `status: error`
with a message naming the table and the IDs not reset, and the attempt is logged with
`notReset`. Templates that extend or include the deleted one are not checked — neither
does the Studio. The path is canonicalised, a symlink or a path through a linked folder is
refused (lessons of `file:delete`). Contao 5.3 has no Template Studio — the rules are
rebuilt here, not called. Nr. 51 of the ConpAI 1.0 acceptance test: cleaning up c5 needed
`ssh rm`.

**`contao:cache:clear`** runs Symfony's `cache:clear` in the same process and then logs
*"Purged the internal cache"* in `monolog.logger.contao.cron` — what
`Automator::purgeInternalCache()` writes when the back end purges — with the operator and
`source = CLI`. Writing after the clear works because Symfony's `cache:clear` keeps the
running container's directory for in-process callers (`.legacy`), from which Contao's table
handler resolves its connection lazily. If writing fails, the answer says `logged: false`.
The entry says "internal cache" as Contao does, although this is a full `cache:clear` with
container rebuild. Nr. 50: the audit of phase 5 found every step in `tl_log` except
`cache clear`.

## Writing templates: refresh as the Template Studio does (v0.20.0)

`contao:template:write` calls `Service\Template\TemplateCacheRefresher` after writing:
`ContaoFilesystemLoader::warmUp(true)`, then `Environment::removeCache()` for every template
outside `backend/` — what Contao's Template Studio does after saving
(`AbstractOperation::refreshTemplateHierarchy()`, `CacheInvalidator::invalidateCache()`). The
answer carries `templateCacheRefreshed: true`; if the refresh throws, the file is written
anyway and `cacheWarning` says to run `cache clear`.

When the refresh cannot reach everything from the console, `templateCacheRefreshed` is
`false` and `cacheWarning` says what (review 2026-09-17):
- **Contao 5.3** keeps the hierarchy in `cache.system`. With APCu that includes an APCu
  layer the console never sees (`apc.enable_cli` is off), so the web server may keep the
  old list. Detected by identity: the loader's `cachePool` is the injected `cache.system`
  service; 5.7 uses `cache.app`, a filesystem pool.
- **Twig before 3.15** has no `Environment::removeCache()` (Contao 5.3 allows `^3.10.2`):
  the hierarchy is refreshed, compiled templates are not.
- Not detectable from here, same limit as `cache:clear`: with
  `opcache.validate_timestamps=0` the web server's opcache keeps compiled PHP files until it
  is reset.

`routeConflicts()` catches `\Exception` only — an `\Error` stays visible, because only the
pure `selectRouteConflicts()` is unit-tested and the Contao glue is verified live.

> 🟡 Up to v0.19.0 it only wrote the file. In production Contao caches the template
> hierarchy and Twig does not auto-reload: a new variant was refused as `customTpl` ("not a
> template Contao offers") and an edited override kept rendering the old version until
> `cache clear`. Invisible before v0.16.0, when `customTpl` was not checked. Nr. 47 of the
> ConpAI 1.0 acceptance test, 2026-09-17.

## Calling Contao's callbacks from the console (v0.16.0)

**`Service\Dca\RecordDataContainer::create($table, $id, $record)`** is how this bundle calls
a callback that expects a DataContainer. It overrides `getCurrentRecord()` and
`getActiveRecord()` to answer with the given record: both ask the permission voters and
fail without a back-end user, and `getActiveRecord()`, the palette code and many listeners
go through them. Setting `objActiveRecord` by reflection is **not enough** — measured,
Contao's `TemplateOptionsListener` still got nothing. Signatures identical in 5.3/5.7/6.0.
A factory, not a named `DC_Table` subclass under `src/`, which autowiring would try to
register.

Used by:

| | what | why |
|---|---|---|
| `OptionsResolver`, `contao:dca:options` | a field's `options_callback` | page types from the `PageRegistry` (`#[AsPage]`), templates per element type |
| `contao:dca:palette` | `DataContainer::getPalette()` | mandatory fields of one kind of record (selectors, sub-palettes, `onpalette_callback`) |

A callback that needs more (a request, a user) throws and is reported as unresolvable; the
image size list returns `[]` on the console — "not known", not "none".

**`options_callback` is still not enforced on write in general** (see above) — with one
exception, `customTpl`: `TemplateOptionsListener` needs only the element type.
`refuseUnknownTemplates()` checks it when the list can be resolved and refuses nothing
when it cannot.

## Structured fields: read as arrays, written as JSON (v0.16.0)

`Service\Dca\StructuredFields::storesArray()` is the one definition of "Contao stores this
as a serialized array": the structured input types (`STRUCTURED_INPUT_TYPES`) and
`eval.multiple` without `eval.csv`; `fileTree` excluded. Three users:

- `refuseUnstructuredValues()` (write, v0.12.0) — types only
- `convertJsonStructuredFields()` (write) — a JSON array/object becomes the serialized form,
  leaves as strings; runs first in `convertFields()`; `inputUnit` keeps its own JSON path
- `convertStructuredFieldsForRead()` (read) — every model read and `record list`

**Contract since v0.16.0: a caller reads arrays and can write them back unchanged.**
`ContentReadCommand::postProcessRow()` still unpacks `headline`, now a no-op.

## Versions and reused IDs (v0.16.0)

Create commands call `createVersion($table, $id, created: true)`, cloners
`createInitialVersion()`: the first version's `description` is `VersionManager::CREATED`.
Everything older under that ID belongs to a deleted record whose ID the database handed
out again — on c5 routinely. `belongsToEarlierRecord()` drives the refusal in `version
restore` and `before_creation` in `version list`; a create answers `earlierVersions`.
`tl_undo` could not tell: it is purged after the undo period.

**`VersionManager::ALLOWED_TABLES` must contain every table the code versions** —
`VersionAllowListTest` scans `src/` and fails otherwise. It held the ten tables of the
April audit while 22 were versioned.

## Layout modules (v0.16.0)

`contao:layout:module --layout --module --col [--remove]` validates module, theme and
column. Columns of a classic layout come from `LayoutModuleCommand::legacyColumns()`,
copied from Contao's `ModuleWizard`. **Quote column names in SQL here: `rows` is a reserved
word in MySQL 8** — the first live run failed with a syntax error no unit test could see.

Since v0.24.0 `--module content-<id>` puts a **theme's content element** into a column —
Contao 5.7 stores it in `modules` as `mod: "content-<id>"` (`ModuleWizard`, `PageRegular`).
The element must be a `tl_content` row with `ptable = tl_theme` and the layout's theme as
`pid`; removing does not look the element up, so a deleted one can still be taken out (Nr. 68).

## fileTree values on write (v0.24.0)

`convertFields()` refuses a `fileTree` value that resolves to no file
(`refuseInvalidFileTreeValues()`, on the raw input): single — a UUID, or the 16-byte binary;
multiple — a JSON list or comma list of UUIDs, or Contao's serialized form. A multiple field
takes the JSON list reads answer it with, so a read value can be written back. Until v0.24.0
`external=["<uuid>"]` stored the JSON text and answered ok, and a comma list dropped a part
that was not a UUID without a word (Nr. 69, `FileTreeValueRefusalTest`).

## Lines on Contao's log channels

A line written to `monolog.logger.contao.*` takes `$this->logContext(ContaoContext::…)`.
Without it Contao's processor labels a console line FE / N/A (Nr. 59, Nr. 66).
`ContaoChannelContextTest` scans `src/` for such lines.

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
