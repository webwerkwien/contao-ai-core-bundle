<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

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
    use SiblingPositions;

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

        // Review H3: with --after the element takes the sibling's parent table.
        if ($this->hasPtableOption() && '' !== $after && '' !== (string) ($this->input->getOption('ptable') ?? '')) {
            return $this->outputError('--ptable goes with --to; with --after the element takes the sibling\'s parent table.');
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
     * @throws \InvalidArgumentException when the sibling is the record, or either does not exist
     */
    protected function positionAfter(string $table, int $id, int $sibling): array
    {
        if ($sibling === $id) {
            throw new \InvalidArgumentException(\sprintf('A %s record cannot be moved behind itself. Nothing was written.', $table));
        }

        // Before the renumbering below writes anything (review W1): a mistyped ID
        // renumbered someone else's siblings and only then answered "not found".
        if ([] === $this->storedRow($table, $id)) {
            throw new \InvalidArgumentException(\sprintf('No %s record with ID %d to move. Nothing was written.', $table, $id));
        }

        return $this->slotAfter($table, $sibling, $id);
    }
}
