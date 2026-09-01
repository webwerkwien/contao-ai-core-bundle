<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FormModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a form — the container its fields live in.
 *
 * The form generator was the last content module readable but not writable.
 * `form list` and `form fields` have existed for a while; nothing could create
 * either half.
 *
 * `--title` is the only dedicated option. The rest comes from `--set`, and what
 * is *required* is read from the DCA — which matters more here than in most
 * tables, because `tl_form`'s mandatory fields are almost all conditional:
 *
 *   form create --title "Contact"                          → accepted
 *   form create --title "Contact" --set sendViaEmail=1     → refused: recipient, subject
 *
 * `recipient` and `subject` are mandatory, but they sit in the `sendViaEmail`
 * subpalette. A form that only stores its values needs neither, and demanding
 * them always would refuse a perfectly ordinary form.
 *
 * **The alias is generated and then checked**, the way `tl_form::generateAlias`
 * does it. Contao refuses two forms with the same alias and refuses a purely
 * numeric one, and both refusals matter: the alias is how a form is addressed,
 * so a duplicate does not fail loudly — it routes to whichever record the query
 * happens to return first.
 */
#[AsCommand(name: 'contao:form:create', description: 'Create a form')]
class FormCreateCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Form title');
        $this->addOption('alias', null, InputOption::VALUE_OPTIONAL, 'Form alias (generated from the title if omitted)', '');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = (string) $this->input->getOption('title');

        if ('' === $title) {
            return $this->outputError('--title is required');
        }

        if (!isset($GLOBALS['TL_DCA']['tl_form']['fields'])) {
            Controller::loadDataContainer('tl_form');
        }

        $missing = $this->missingMandatoryFields('tl_form', 'default', $fields, ['title', 'alias']);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Also required with these settings: %s. Pass them with --set. '
                . '(recipient and subject are only required once sendViaEmail is on.)',
                implode(', ', $missing),
            ));
        }

        $alias = (string) ($this->input->getOption('alias') ?: StringUtil::generateAlias($title));

        if (preg_match('/^[1-9]\d*$/', $alias)) {
            return $this->outputError(\sprintf(
                'A purely numeric alias ("%s") is not allowed — Contao cannot tell it apart '
                . 'from a record ID.',
                $alias,
            ));
        }

        if ($this->aliasTaken($alias)) {
            return $this->outputError(\sprintf(
                'The alias "%s" is already in use. Form aliases must be unique: a duplicate '
                . 'does not fail, it routes to whichever record comes back first.',
                $alias,
            ));
        }

        $fields = $this->preparedFields('tl_form', [
            'title' => $title,
            'alias' => $alias,
        ], $fields);

        $form         = new FormModel();
        $form->tstamp = time();

        foreach ($fields as $key => $value) {
            $form->$key = $value;
        }

        $form->save();
        $this->createVersion('tl_form', (int) $form->id);

        $this->outputSuccess([
            'id'    => (int) $form->id,
            'title' => $title,
            'alias' => $alias,
        ]);

        return Command::SUCCESS;
    }

    private function aliasTaken(string $alias): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_form WHERE alias = ?',
            [$alias],
        ) > 0;
    }
}
