<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:page:publish', description: 'Publish or unpublish a Contao page')]
class PagePublishCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id',     InputArgument::REQUIRED, 'Page ID');
        $this->addArgument('action', InputArgument::OPTIONAL, 'publish or unpublish', 'publish');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $id     = (int) $this->input->getArgument('id');
        $action = $this->input->getArgument('action');
        $page   = PageModel::findById($id);

        if ($page === null) {
            return $this->outputError("Page not found: $id");
        }
        if (!in_array($action, ['publish', 'unpublish'], true)) {
            return $this->outputError("Invalid action '$action'. Use: publish or unpublish");
        }

        // 🔴 H-2 (Audit 2026-09-02): hier stand `$page->published = …; $page->save();` —
        // am Writer vorbei. Folge: kein tl_version-Eintrag, also war ein
        // versehentliches publish über die Versionshistorie nicht rückholbar.
        //
        // 🎯 Der Writer nimmt fertige Werte und erledigt Snapshot, tstamp und
        // save. Werte zu BAUEN bleibt Sache des Befehls — genau so steht es im
        // Vertrag von RecordWriterInterface. Diese Befehle hatten nichts zu
        // bauen und trotzdem selbst gespeichert.
        $publish = 'publish' === $action;

        $this->writer()->update(
            PageModel::getTable(),
            $id,
            ['published' => $this->booleanFlag($publish)],
            $this->resolveOperator(),
        );

        $this->outputSuccess(['id' => $id, 'published' => $publish]);
        return Command::SUCCESS;
    }
}
