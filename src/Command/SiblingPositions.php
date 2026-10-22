<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\Database;

/**
 * "Directly behind this sibling" for a sorted table, as the back end's paste does it
 * (`DC_Table::getNewPosition()`, 5.3, 5.7, 6.0): halfway to the next sibling, or the
 * siblings renumbered in steps of 128 when no integer is left in between.
 *
 * Shared by the move commands (`--after`, v1.2.0) and the create commands (`--after`,
 * v1.12.0) — one rule, not two. Until v1.12.0 a new element between two others meant
 * computing `--set sorting=` by hand (Consho docs on consho.eu, 2026-10-22).
 */
trait SiblingPositions
{
    /**
     * Parent, sorting and — for a table with a per-record parent table — ptable for a
     * record placed directly behind $sibling.
     *
     * $moving is the record being moved, if any: it stays in the renumbering at its old
     * place (so a move refused afterwards leaves the order as it was) but is skipped
     * as the "next" sibling. A new record has none.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the sibling does not exist
     */
    protected function slotAfter(string $table, int $sibling, ?int $moving = null, string $what = 'move'): array
    {
        $row = $this->storedRow($table, $sibling);

        if ([] === $row) {
            throw new \InvalidArgumentException(\sprintf(
                'No %s record with ID %d to %s behind. Nothing was written. Read the ID back before using it.',
                $table,
                $sibling,
                $what,
            ));
        }

        $dynamic = $this->isDynamicPtable($table);
        $pid     = (int) $row['pid'];
        $ptable  = $dynamic ? (string) ($row['ptable'] ?? '') : '';
        $current = (int) $row['sorting'];

        $siblings = $this->siblingsInOrder($table, $pid, $ptable);
        $others   = array_values(array_filter(array_keys($siblings), static fn (int $key): bool => $key !== $moving));
        $index    = array_search($sibling, $others, true);
        $next     = false === $index ? null : ($siblings[$others[$index + 1] ?? -1] ?? null);

        if (null === $next) {
            $sorting = $this->sortingAfter($current);
        } elseif ($next - $current > 1) {
            $sorting = $current + intdiv($next - $current, 2);
        } else {
            $sorting = $this->renumberAround($table, $siblings, $sibling);
        }

        $fields = ['pid' => $pid, 'sorting' => $sorting];

        return $dynamic ? $fields + ['ptable' => $ptable] : $fields;
    }

    /**
     * Renumber the siblings in steps of 128, leaving the slot behind $sibling free.
     *
     * As `DC_Table::getNewPosition()` does, directly on `sorting`: a version per
     * sibling would record a change nobody made. Every sibling keeps its place —
     * the record being moved included, when it shares the parent — so a write
     * refused afterwards leaves the order as it was, only renumbered.
     *
     * @param array<int, int> $siblings id => sorting, in order
     *
     * @return int the free slot
     */
    private function renumberAround(string $table, array $siblings, int $sibling): int
    {
        $sorting = 0;
        $slot    = 0;

        foreach ($siblings as $siblingId => $old) {
            $sorting += 128;

            if ($sorting !== $old) {
                $this->writeSorting($table, $siblingId, $sorting);
            }

            if ($siblingId === $sibling) {
                $sorting += 128;
                $slot     = $sorting;
            }
        }

        return $slot;
    }

    private function isDynamicPtable(string $table): bool
    {
        // The position is computed before any write path has loaded the DCA. Unloaded,
        // tl_content read as a table without a per-record parent table: --ptable was
        // dropped and the parent checked against the stored one — on web.werk.wien 25
        // elements moved "to article 35" ended up below content elements 35–38, with
        // status ok (v1.11.2). The unit tests had set the DCA by hand.
        if (!isset($GLOBALS['TL_DCA'][$table]['config'])) {
            $this->loadDca($table);
        }

        return (bool) ($GLOBALS['TL_DCA'][$table]['config']['dynamicPtable'] ?? false);
    }

    /** Contao's loader; a method of its own so a test can stand in for it. */
    protected function loadDca(string $table): void
    {
        Controller::loadDataContainer($table);
    }

    /**
     * The records below a parent.
     *
     * `$table` is always the literal table of the command's model, never input.
     *
     * @return array<int, int> id => sorting, in order
     */
    protected function siblingsInOrder(string $table, int $pid, string $ptable): array
    {
        $sql    = 'SELECT id, sorting FROM ' . $table . ' WHERE pid=?';
        $params = [$pid];

        if ('' !== $ptable) {
            $sql     .= ' AND ptable=?';
            $params[] = $ptable;
        }

        $rows     = Database::getInstance()->prepare($sql . ' ORDER BY sorting, id')->execute(...$params)->fetchAllAssoc();
        $siblings = [];

        foreach ($rows as $row) {
            $siblings[(int) $row['id']] = (int) $row['sorting'];
        }

        return $siblings;
    }

    protected function writeSorting(string $table, int $id, int $sorting): void
    {
        Database::getInstance()->prepare('UPDATE ' . $table . ' SET sorting=? WHERE id=?')->execute($sorting, $id);
    }

    /**
     * --after of a create command: the sibling's ID, null when not given, or an error
     * text. With --after the parent is the sibling's, so --pid (and --ptable) go.
     *
     * @param list<string>         $excludes options that must not come with --after
     * @param array<string, mixed> $set      the --set pairs: pid, ptable and sorting there
     *                                        would override the slot (review v1.12.0)
     *
     * @return int|string|null
     */
    protected function afterOption(array $excludes, array $set = []): int|string|null
    {
        $after = (string) ($this->input->getOption('after') ?? '');

        if ('' === $after) {
            return $this->input->hasParameterOption('--after') ? '--after needs a record ID.' : null;
        }

        if (!ctype_digit($after) || 0 === (int) $after) {
            return \sprintf('--after must be a record ID, not "%s".', $after);
        }

        foreach ($excludes as $option) {
            if ($this->input->hasParameterOption('--' . $option)) {
                return \sprintf('--after takes the parent of the sibling; leave out --%s.', $option);
            }
        }

        foreach (['pid', 'ptable', 'sorting'] as $field) {
            if (\array_key_exists($field, $set)) {
                return \sprintf('--after takes the parent and position of the sibling; leave out --set %s=.', $field);
            }
        }

        return (int) $after;
    }
}
