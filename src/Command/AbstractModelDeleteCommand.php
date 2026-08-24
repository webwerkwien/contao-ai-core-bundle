<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\RecordCascadeCollector;

abstract class AbstractModelDeleteCommand extends AbstractWriteCommand
{
    abstract protected function modelClass(): string;
    abstract protected function entityName(): string;

    protected Connection $connection;
    protected RecordCascadeCollector $cascadeCollector;

    public function __construct(protected readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    #[Required]
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    #[Required]
    public function setCascadeCollector(RecordCascadeCollector $cascadeCollector): void
    {
        $this->cascadeCollector = $cascadeCollector;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, $this->entityName() . ' ID');
    }

    protected function doExecute(array $fields): int  // $fields intentionally unused — delete takes no field input
    {
        $this->framework->initialize();
        $id    = (int) $this->input->getArgument('id');
        $class = $this->modelClass();
        $record = $class::findById($id);

        if ($record === null) {
            return $this->outputError($this->entityName() . " not found: $id");
        }

        $table = $class::getTable();

        // Contao has no foreign keys: whatever is not collected here stays behind
        // as an orphan that no back end view shows and no clean-up task reclaims.
        // See RecordCascadeCollector for what DC_Table::delete() does and why we
        // cannot call it from the console.
        $collected = $this->cascadeCollector->collect($table, $id);
        $rows      = $this->readRows($collected);

        // One undo entry for the whole set, in the shape DC_Table::undo() expects:
        // [table => [row, ...]]. Restoring brings the children back with the parent.
        $this->snapshotToUndo($table, $id, $rows, $record->row());

        $deleted = $this->deleteRows($collected);

        $this->outputSuccess([
            'id'        => $id,
            'deleted'   => true,
            'undoable'  => true,
            'cascade'   => $this->summarise($collected),
            'rowsTotal' => $deleted,
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, list<int>> $collected
     * @return array<string, list<array<string, mixed>>>
     */
    private function readRows(array $collected): array
    {
        $rows = [];

        foreach ($collected as $table => $ids) {
            foreach ($ids as $id) {
                $row = $this->connection->fetchAssociative(
                    'SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?',
                    [$id]
                );

                if (false !== $row) {
                    $rows[$table][] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Children first, so a half-failed run never leaves a parent pointing at rows
     * that are already gone.
     *
     * @param array<string, list<int>> $collected
     */
    private function deleteRows(array $collected): int
    {
        $affected = 0;

        foreach (array_reverse($collected, true) as $table => $ids) {
            foreach ($ids as $id) {
                $affected += (int) $this->connection->delete($table, ['id' => $id]);
            }
        }

        return $affected;
    }

    /**
     * @param array<string, list<int>> $collected
     * @return array<string, int>
     */
    private function summarise(array $collected): array
    {
        return array_map('\count', $collected);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $rows
     * @param array<string, mixed>                      $rootRow
     */
    private function snapshotToUndo(string $table, int $id, array $rows, array $rootRow): void
    {
        if (!$rows) {
            return;
        }

        $affected = array_sum(array_map('\count', $rows));

        $this->connection->insert('tl_undo', [
            'pid'           => (int) ($rootRow['author'] ?? 0),
            'tstamp'        => time(),
            'fromTable'     => $table,
            'query'         => \sprintf('DELETE FROM %s WHERE id=%d', $table, $id),
            'affectedRows'  => $affected,
            'data'          => serialize($rows),
        ]);
    }
}
