<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\EntityClonerInterface;

/**
 * CLI surface for the Phase-9 macro clone primitive. Routes the request to
 * the first registered EntityCloner that supports the requested table.
 *
 * Stateless: the command only orchestrates; the cloner does the cascade
 * inside its own DB transaction. Plugin-conditional cloner registrations
 * (services_news.yaml, services_calendar.yaml, ...) decide which tables are
 * actually clonable in a given installation — when no cloner is registered
 * for the requested table, the response is a structured "no cloner" error
 * instead of a fatal.
 */
#[AsCommand(
    name: 'contao:record:clone',
    description: 'Clone a Contao container record (and all its children) via a registered EntityCloner service'
)]
class RecordCloneCommand extends Command
{
    /**
     * @param iterable<EntityClonerInterface> $cloners Tagged-iterator over every registered cloner.
     */
    public function __construct(
        #[TaggedIterator('contao_ai.entity_cloner')]
        private readonly iterable $cloners = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('source-table',  null, InputOption::VALUE_REQUIRED, 'Container table to clone (e.g. tl_news_archive)');
        $this->addOption('source-id',     null, InputOption::VALUE_REQUIRED, 'ID of the source container record');
        $this->addOption('modifications', null, InputOption::VALUE_OPTIONAL, 'JSON object of root-record field overrides (e.g. {"title":"…"})', '{}');
        $this->addOption('operator',      null, InputOption::VALUE_REQUIRED, 'Audit-trail user identifier — backend integrations pass the Contao username, CLI falls back to $_SERVER[USER].', '');
        $this->addOption('recursive',     null, InputOption::VALUE_NONE, 'Walk container-of-container hierarchies (e.g. PageCloner: clone the entire subpage tree, not just the root page).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table    = (string) $input->getOption('source-table');
        $idRaw    = (string) $input->getOption('source-id');
        $modsRaw  = (string) $input->getOption('modifications');
        $operator = (string) $input->getOption('operator');

        if ('' === $table || '' === $idRaw || !ctype_digit($idRaw)) {
            return $this->error($output, '--source-table and a numeric --source-id are required');
        }

        $modifications = [];
        if ('' !== $modsRaw) {
            $decoded = json_decode($modsRaw, true);
            if (\is_array($decoded)) {
                $modifications = $decoded;
            }
        }

        $sourceId  = (int) $idRaw;
        $recursive = (bool) $input->getOption('recursive');
        $clonerOptions = ['recursive' => $recursive];

        foreach ($this->cloners as $cloner) {
            if (!$cloner->supports($table)) {
                continue;
            }
            try {
                $result = $cloner->clone($sourceId, $modifications, $operator, $clonerOptions);
            } catch (\Throwable $e) {
                return $this->error($output, $e->getMessage());
            }
            $output->writeln(json_encode(
                ['status' => 'ok'] + $result,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ));
            return Command::SUCCESS;
        }

        return $this->error(
            $output,
            \sprintf('Kein Cloner für Tabelle "%s" registriert.', $table)
        );
    }

    private function error(OutputInterface $output, string $message): int
    {
        $output->writeln(json_encode(
            ['status' => 'error', 'message' => $message],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        return Command::FAILURE;
    }
}
