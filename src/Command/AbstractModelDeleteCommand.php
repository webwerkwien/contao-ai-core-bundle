<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractModelDeleteCommand extends AbstractWriteCommand
{
    abstract protected function modelClass(): string;
    abstract protected function entityName(): string;

    protected Connection $connection;

    public function __construct(protected readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    #[Required]
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
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

        // Pre-delete snapshot in tl_undo so the deletion is reversible via
        // backend module "Wiederherstellen" — same UX as Contao's regular
        // DC_Table::delete() flow. Operator (CLI) and editor (backend agent)
        // both benefit from the undo trail.
        $this->snapshotToUndo($table, $id, $record->row());

        $record->delete();
        $this->outputSuccess(['id' => $id, 'deleted' => true, 'undoable' => true]);
        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotToUndo(string $table, int $id, array $row): void
    {
        $operatorId = (int) ($row['author'] ?? 0);

        $this->connection->insert('tl_undo', [
            'pid'           => $operatorId,
            'tstamp'        => time(),
            'fromTable'     => $table,
            'query'         => \sprintf('DELETE FROM %s WHERE id=%d', $table, $id),
            'affectedRows'  => 1,
            'data'          => serialize([$table => [$row]]),
        ]);
    }
}
