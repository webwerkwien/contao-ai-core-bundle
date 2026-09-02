<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:user:delete', description: 'Delete a backend user')]
class UserDeleteCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED, 'Username to delete');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $username = $this->input->getArgument('username');

        $user = UserModel::findByUsername($username);
        if ($user === null) {
            return $this->outputError("User not found: $username");
        }

        $id = (int) $user->id;

        // 🔴 H-1 (Audit 2026-09-02): hier stand `$user->delete()` — ein nacktes
        // Contao\Model::delete(). Kein tl_undo-Snapshot, keine Kaskade, kein
        // Systemlog: der Befehl meldete `deleted` und `contao:undo:restore`
        // konnte den Datensatz nicht zurückholen.
        //
        // 🎯 Der Writer existiert seit v0.2.16 genau dafür — "the one place a
        // record is persisted, together with everything a write owes the audit
        // trail". Diese beiden Befehle sind daran vorbeigelaufen, weil sie den
        // Datensatz über den Benutzernamen suchen und deshalb nie von
        // AbstractModelDeleteCommand erben konnten. Die Abkürzung kostete die
        // Wiederherstellbarkeit.
        //
        // `undoable` steht erst NACH dem Snapshot in der Antwort: eine Zusage,
        // die vor ihrem Beleg geschrieben wird, ist wieder nur eine Behauptung.
        $result = $this->writer()->delete(
            UserModel::getTable(),
            $id,
            $this->resolveOperator(),
            $this->resolveOperatorUserId(0),
        );

        $this->outputSuccess([
            'username'   => $username,
            'deleted_id' => $id,
            'undoable'   => true,
        ] + $result);
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
