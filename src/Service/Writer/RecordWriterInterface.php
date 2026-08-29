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
 */
interface RecordWriterInterface
{
    /**
     * Apply values to an existing record.
     *
     * The version snapshot is taken **before** the save, so it holds the state
     * being replaced — that is what makes it restorable.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<string>|null the written field names, or null when the record does not exist
     */
    public function update(string $table, int $id, array $fields, string $operator): ?array;

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
