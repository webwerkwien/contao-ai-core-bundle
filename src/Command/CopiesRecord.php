<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\RecordCopier;

/**
 * The back end's copy and paste for a sorted table: directly behind the source by
 * default, `--after <sibling>` directly behind that sibling, `--to <parent>` behind
 * the parent's last child. The copy itself is RecordCopier's: every field of the
 * source whatever the caller may edit, as in the back end, where the field rights
 * apply in the edit form that follows a copy.
 *
 * @phpstan-require-extends AbstractWriteCommand
 */
trait CopiesRecord
{
    use SiblingPositions;

    abstract protected function copier(): RecordCopier;

    abstract protected function copyTable(): string;

    abstract protected function entityName(): string;

    /** Whether the table's parent table is per record (tl_content). */
    protected function hasPtableOption(): bool
    {
        return false;
    }

    /**
     * Values the copy takes instead of its source's, besides its position.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $position
     *
     * @return array<string, mixed>
     */
    protected function copyOverrides(array $source, array $position): array
    {
        return [];
    }

    protected function configure(): void
    {
        // --set stays registered only because execute() reads it; a copy refuses it.
        parent::configure();
        $this->addArgument('id', InputArgument::OPTIONAL, $this->entityName() . ' ID to copy');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Parent ID — the copy goes behind its last child');
        $this->addOption('after', null, InputOption::VALUE_REQUIRED, 'Sibling ID — the copy goes directly behind it (default: directly behind the source)');

        if ($this->hasPtableOption()) {
            $this->addOption('ptable', null, InputOption::VALUE_REQUIRED, 'Parent table for --to (tl_article, tl_news, tl_content, …); default: the source\'s');
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function doExecute(array $fields): int
    {
        if ([] !== $fields) {
            return $this->outputError('A copy takes every field of its source and takes no --set. Change fields afterwards with the update command.');
        }

        $id     = (string) ($this->input->getArgument('id') ?? '');
        $to     = (string) ($this->input->getOption('to') ?? '');
        $after  = (string) ($this->input->getOption('after') ?? '');
        $ptable = $this->hasPtableOption() ? (string) ($this->input->getOption('ptable') ?? '') : '';

        if (!ctype_digit($id) || 0 === (int) $id) {
            return $this->outputError('Give the ID of the ' . strtolower($this->entityName()) . ' to copy.');
        }

        if ('' !== $to && '' !== $after) {
            return $this->outputError('Give --to <parent ID> or --after <sibling ID>, not both. Without either, the copy goes directly behind the source.');
        }

        foreach (['--to' => $to, '--after' => $after] as $name => $value) {
            if ('' !== $value && (!ctype_digit($value) || 0 === (int) $value)) {
                return $this->outputError(\sprintf('%s must be a record ID, not "%s".', $name, $value));
            }
        }

        if ('' !== $ptable && '' === $to) {
            return $this->outputError('--ptable goes with --to; otherwise the copy takes the parent table of the record it goes behind.');
        }

        $this->framework->initialize();

        $table  = $this->copyTable();
        $source = $this->storedRow($table, (int) $id);

        if ([] === $source) {
            return $this->outputError(\sprintf('%s not found: %s. Nothing was written.', $this->entityName(), $id));
        }

        try {
            $position = '' !== $to
                ? $this->positionUnder($table, (int) $to, '' !== $ptable ? $ptable : (string) ($source['ptable'] ?? ''))
                : $this->slotAfter($table, (int) ('' !== $after ? $after : $id), null, 'put the copy');
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }

        $result = $this->copier()->copy($table, (int) $id, $position + $this->copyOverrides($source, $position), $this->resolveOperator(), $this->resolveOperatorLanguage());
        $this->cacheTags?->recordChanged($table, $result['id']);

        $this->outputSuccess(['id' => $result['id'], 'sourceId' => (int) $id] + $position + [
            'children'  => $result['children'],
            'notCopied' => $result['notCopied'],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Behind the last child of $parent; refused when the parent does not exist.
     *
     * A parent inside the source's own tree is allowed, as in the back end, which
     * disables that paste for cut only: the copier reads the tree before it writes,
     * so the copy does not copy itself.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function positionUnder(string $table, int $parent, string $ptable): array
    {
        $dynamic = $this->isDynamicPtable($table);
        $fields  = ['pid' => $parent] + ($dynamic ? ['ptable' => $ptable] : []);
        $this->refuseMissingParent($table, $fields);

        return $fields + ['sorting' => $this->nextSorting($table, $parent, $dynamic ? $ptable : null)];
    }
}
