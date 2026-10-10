<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\ChangeLanguageLinks;

/**
 * Link a record to its counterpart in the main language (terminal42/contao-changelanguage).
 *
 * The field follows from the table (ChangeLanguageLinks): `languageMain` for pages,
 * articles, news, events and FAQ, `master` for their archives, `languageRoot` for a
 * fallback root. The target is checked against what the back end's widget offers,
 * then written through the record writer — so the package's own save_callback refuses
 * a duplicate link, a version is written and the cache is invalidated as for any
 * other change (v1.4.0).
 *
 * Without a target, `--main 0`, the link is removed.
 */
#[AsCommand(name: 'contao:language:link', description: 'Link a record to its translation in the main language (changelanguage)')]
class LanguageLinkCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ChangeLanguageLinks $links,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('table', null, InputOption::VALUE_REQUIRED, 'Table of the record: ' . implode(', ', ChangeLanguageLinks::TABLES));
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'ID of the record in the other language');
        $this->addOption('main', null, InputOption::VALUE_REQUIRED, 'ID of its counterpart in the main language; 0 removes the link');
    }

    protected function doExecute(array $fields): int
    {
        if ([] !== $fields) {
            return $this->outputError('A link takes --main, no --set.');
        }

        $table = (string) $this->input->getOption('table');
        $id    = (string) $this->input->getOption('id');
        $main  = (string) $this->input->getOption('main');

        if (!\in_array($table, ChangeLanguageLinks::TABLES, true)) {
            return $this->outputError('--table must be one of: ' . implode(', ', ChangeLanguageLinks::TABLES));
        }

        if (!ctype_digit($id) || 0 === (int) $id || !ctype_digit($main)) {
            return $this->outputError('--id must be a record ID and --main a record ID or 0.');
        }

        $this->framework->initialize();

        $record = $this->storedRow($table, (int) $id);

        if ([] === $record) {
            return $this->outputError("Record not found: {$table}:{$id}");
        }

        $field = $this->links->field($table, $record);

        if ((int) $main > 0 && null !== ($refusal = $this->links->refusal($table, (int) $id, $record, (int) $main))) {
            return $this->outputError($refusal);
        }

        if ((int) ($record[$field] ?? 0) === (int) $main) {
            $this->outputSuccess(['table' => $table, 'id' => (int) $id, 'field' => $field, 'main' => (int) $main, 'unchanged' => true]);

            return Command::SUCCESS;
        }

        $write = $this->convertFields($table, [$field => (int) $main], (int) $id);

        $this->writer()->update($table, (int) $id, $write, $this->resolveOperator());

        $this->outputSuccess(['table' => $table, 'id' => (int) $id, 'field' => $field, 'main' => (int) $main, 'previous' => (int) ($record[$field] ?? 0)]);

        return Command::SUCCESS;
    }
}
