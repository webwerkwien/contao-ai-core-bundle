<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Writer;

/**
 * The one place a record is persisted, together with everything a write owes the
 * audit trail: a version snapshot, the cascade, the undo entry.
 *
 * **Why this exists.** Until v0.2.16 the write path was the commands themselves:
 * `AbstractWriteCommand` assigned to a model and called `save()`, and the rules
 * around that accreted one bugfix at a time — the cascade and `tl_undo` entry in
 * v0.2.8/v0.2.9, the `''`-in-a-tinyint normalisation in v0.2.10, the system log
 * in v0.2.11–v0.2.13. Each of those landed in a different class, and none of them
 * could be tested without booting a Contao container, which is why several of
 * them shipped uncovered.
 *
 * **Why the signatures speak tables and fields, not models.** The whole point of
 * an interface here is that a second implementation can replace it. Contao 6.1 is
 * expected to bring core operations (`DataContainerStateProcessor`) that own
 * versioning, undo and validation — an implementation built on those never sees a
 * `Contao\Model`. An interface phrased in models would look swappable and not be.
 * See the decision points in the Contao 6 notes before building implementation B:
 * as of 2026-08-29 that processor is three TODO comments, so this is a seam, not
 * a migration.
 *
 * What deliberately stays outside: building a record's field values. Which fields
 * a page create takes, how an alias is derived, how an `inputUnit` is serialised —
 * that is per-entity knowledge and belongs in the command that owns the entity.
 * The writer persists what it is handed.
 *
 * ## Public API for extensions (v1.4.0)
 *
 * An extension's own `#[AiContract]` commands may write through this interface
 * (autowire `RecordWriterInterface`) and get what the bundle's commands get: a version
 * per write through Contao's Versions class, the record callbacks of the DCA run as
 * DC_Table runs them, an undo entry with the cascade on delete, cache invalidation,
 * one transaction per write. Decided with the maintainer on 2026-10-10; changes to
 * these signatures need a major version.
 *
 * Values are handed over in their stored form. The fields' save_callbacks run when the
 * caller asks for them with `$saveCallbacks` — for every field handed over, where
 * DC_Table runs them: on a create after the INSERT and `oncreate`, before `onsubmit`;
 * on an update before the save. Consho's onsubmit listeners work with values only
 * the save_callbacks produce (net prices, shop relations), so a caller cannot run them
 * itself around create() — onsubmit would see the raw values (asked for by Consho,
 * 2026-10-10). Without the flag the caller may run them through DcaCallbacks::save().
 *
 * The table's `onbeforesubmit_callback`s run on every write that is not a move, with
 * or without the flag, as `onsubmit` does (v1.5.0): after the save_callbacks, before the
 * write, with the values and a data container on the stored record. Their return value
 * is what gets written — they may add, change and remove fields; on a create a removed
 * column keeps the value already inserted (DC_Table's create inserts defaults first,
 * this one the values). Every value handed over counts as submitted, unchanged ones
 * too — DC_Table submits only what changed. A field the DCA
 * declares without a column (a virtual field, a gross amount stored as net) may be
 * handed over when the table has such callbacks; what is still no column after them is
 * refused by name.
 *
 * @api
 */
interface RecordWriterInterface
{
    /**
     * Insert a record (v1.4.0).
     *
     * `tstamp` is set; a key that is no column is refused before anything is written,
     * unless the DCA declares it and an onbeforesubmit_callback may take it over (above).
     * Then, as a create of the bundle's own commands: oncreate, with `$saveCallbacks`
     * the fields' save_callbacks, onbeforesubmit (what both change is written to the
     * record; a field without a column is not inserted, only handed to it), onsubmit,
     * the record's first version (marked as its own, so versions of an earlier record
     * with the same ID are told apart), cache invalidation — one transaction, so a
     * refusing callback leaves no record behind.
     *
     * @param array<string, mixed> $fields stored values, without `id`
     *
     * @return int the new record's ID
     *
     * @throws \InvalidArgumentException a key is not a column, or a callback refused
     */
    public function create(string $table, array $fields, string $operator, bool $saveCallbacks = false): int;

    /**
     * Apply values to an existing record.
     *
     * Versioned as DC_Table::edit() does (v1.4.0): the state being replaced is kept
     * if the record has no version yet, the new state is versioned after the save.
     * In between the record callbacks run — onsubmit for a field, oncut for a new
     * parent — and the whole write is one transaction, so a refusing callback leaves
     * nothing behind. With `$saveCallbacks` the fields' save_callbacks run first, on the
     * stored record, then onbeforesubmit; their return values are written.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<string>|null the written field names — what an onbeforesubmit_callback
     *                           added included, what it removed not — or null when the
     *                           record does not exist
     */
    public function update(string $table, int $id, array $fields, string $operator, bool $saveCallbacks = false): ?array;

    /**
     * Delete a record together with everything that cascades from it.
     *
     * Contao has no foreign keys, so whatever is not collected here stays behind
     * as an orphan that no back-end view shows and no clean-up task reclaims.
     * Children are removed before their parents, and the whole set is snapshotted
     * into a single `tl_undo` row so restoring brings the children back with it.
     *
     * @param int $undoUserId Back-end user the undo entry is filed under. This is the
     *   user who *deleted* the record, not its author: the undo module filters
     *   non-admins on it. A plain CLI deletion has no back-end user and passes 0.
     *
     * @return array{cascade: array<string, int>, rowsTotal: int}
     */
    public function delete(string $table, int $id, string $operator, int $undoUserId): array;
}
