<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The back end's cut and paste for a sorted table: `--to <parent>` puts the record
 * behind the last child of the parent, `--after <sibling>` directly behind the
 * sibling, below the sibling's parent.
 *
 * Used by move commands that extend the table's update command, so the write is
 * the update's: parent check, no move below itself, page tree and URL rules,
 * version, log, cache. Only the position is computed here, the way
 * `DC_Table::getNewPosition()` does it (5.3, 5.7, 6.0): halfway to the next
 * sibling, or the siblings renumbered in steps of 128 when no integer is left
 * in between. See MoveCommandTest (v1.2.0).
 *
 * @phpstan-require-extends AbstractModelUpdateCommand
 */
trait MovesRecord
{
    /** Whether the table's parent table is per record (tl_content). */
    protected function hasPtableOption(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        // The write command's --set and --operator, but not the update's --ids: a
        // move places one record. --set stays registered only because execute()
        // reads it; a move refuses it.
        AbstractWriteCommand::configure();
        $this->addArgument('id', InputArgument::OPTIONAL, $this->entityName() . ' ID');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'New parent ID — the record goes behind its last child');
        $this->addOption('after', null, InputOption::VALUE_REQUIRED, 'Sibling ID — the record goes directly behind it, below the same parent');

        if ($this->hasPtableOption()) {
            $this->addOption('ptable', null, InputOption::VALUE_REQUIRED, 'Parent table for --to (tl_article, tl_news, …); default: the current one');
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function doExecute(array $fields): int
    {
        if ([] !== $fields) {
            return $this->outputError('A move changes only the position and takes no --set. Use the update command for field values.');
        }

        $id    = (string) ($this->input->getArgument('id') ?? '');
        $to    = (string) ($this->input->getOption('to') ?? '');
        $after = (string) ($this->input->getOption('after') ?? '');

        if (!ctype_digit($id) || 0 === (int) $id) {
            return $this->outputError('Give the ID of the ' . strtolower($this->entityName()) . ' to move.');
        }

        if (('' === $to) === ('' === $after)) {
            return $this->outputError('Give either --to <parent ID> (behind its last child) or --after <sibling ID> (directly behind it), not both.');
        }

        foreach (['--to' => $to, '--after' => $after] as $name => $value) {
            if ('' !== $value && !ctype_digit($value)) {
                return $this->outputError(\sprintf('%s must be a record ID, not "%s".', $name, $value));
            }
        }

        $this->framework->initialize();

        $class = $this->modelClass();
        $table = $class::getTable();

        try {
            $position = '' !== $after
                ? $this->positionAfter($table, (int) $id, (int) $after)
                : $this->positionAtEnd($table, (int) $id, (int) $to, $this->hasPtableOption() ? (string) ($this->input->getOption('ptable') ?? '') : '');
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        $updated = $this->applyToRecord((int) $id, $position);

        if (null === $updated) {
            return $this->outputError($this->entityName() . " not found: $id");
        }

        $this->outputSuccess(['id' => (int) $id] + $position);

        return Command::SUCCESS;
    }

    /**
     * Behind the last child of the parent.
     *
     * @return array<string, mixed>
     */
    protected function positionAtEnd(string $table, int $id, int $parent, string $ptable): array
    {
        $fields = ['pid' => $parent];

        if (!$this->isDynamicPtable($table)) {
            return $fields + ['sorting' => $this->nextSorting($table, $parent)];
        }

        $siblingsOf = '' !== $ptable ? $ptable : (string) ($this->storedRow($table, $id)['ptable'] ?? '');
        $fields    += ['sorting' => $this->nextSorting($table, $parent, $siblingsOf)];

        return '' !== $ptable ? $fields + ['ptable' => $ptable] : $fields;
    }

    /**
     * Directly behind the sibling, below the sibling's parent.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the sibling is the record or does not exist
     */
    protected function positionAfter(string $table, int $id, int $sibling): array
    {
        if ($sibling === $id) {
            throw new \InvalidArgumentException(\sprintf('A %s record cannot be moved behind itself. Nothing was written.', $table));
        }

        $row = $this->storedRow($table, $sibling);

        if ([] === $row) {
            throw new \InvalidArgumentException(\sprintf(
                'No %s record with ID %d to move behind. Nothing was written. Read the ID back before using it.',
                $table,
                $sibling,
            ));
        }

        $dynamic = $this->isDynamicPtable($table);
        $pid     = (int) $row['pid'];
        $ptable  = $dynamic ? (string) ($row['ptable'] ?? '') : '';
        $current = (int) $row['sorting'];

        $siblings = $this->siblingsInOrder($table, $pid, $ptable, $id);
        $ids      = array_keys($siblings);
        $index    = array_search($sibling, $ids, true);
        $next     = false === $index ? null : ($siblings[$ids[$index + 1] ?? -1] ?? null);

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
     * sibling would record a change nobody made. The order does not change, so a
     * move refused afterwards leaves the siblings as they were, only renumbered.
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
        return (bool) ($GLOBALS['TL_DCA'][$table]['config']['dynamicPtable'] ?? false);
    }

    /**
     * The siblings below a parent, without the record being moved.
     *
     * `$table` is always the literal table of the command's model, never input.
     *
     * @return array<int, int> id => sorting, in order
     */
    protected function siblingsInOrder(string $table, int $pid, string $ptable, int $exclude): array
    {
        $sql    = 'SELECT id, sorting FROM ' . $table . ' WHERE pid=? AND id!=?';
        $params = [$pid, $exclude];

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
}
