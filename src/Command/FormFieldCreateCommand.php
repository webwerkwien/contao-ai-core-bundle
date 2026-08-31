<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FormFieldModel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a form field.
 *
 * `tl_form_field` is `tl_module` in miniature: twenty types, a palette each,
 * and mandatory fields that only apply to some of them. A `submit` needs
 * `slabel`; a `select` needs `name` and `options`; an `explanation` needs
 * neither. Reading `eval.mandatory` alone would demand all of them at once and
 * produce a command nobody can call — the same trap the module command walked
 * into, answered the same way: `missingMandatoryFields()` intersects the flags
 * with the type's palette, which is exactly what `DC_Table` validates.
 *
 * `contao:form-field:types` lists the types and what each one needs.
 *
 * **Options use a short form**, because three of the twenty types cannot be
 * created without them:
 *
 *   --set options="mrs=Mrs.|mr=Mr."     value and label
 *   --set options="red|green|blue"      label doubles as the value
 *
 * See `convertOptionFields()` for why a short form is justified here and not
 * for `tl_settings.allowedAttributes`.
 *
 * **New fields are appended, 128 apart** — the gap Contao's own back end leaves
 * between neighbours so a later drag can land between them without renumbering
 * the whole form. Same rule as image size variants.
 */
#[AsCommand(name: 'contao:form-field:create', description: 'Create a form field')]
class FormFieldCreateCommand extends AbstractWriteCommand
{
    private const SORTING_STEP = 128;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('pid',  null, InputOption::VALUE_REQUIRED, 'Form ID (tl_form) the field belongs to');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Field type, e.g. "text", "select", "submit"');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $pid  = (string) $this->input->getOption('pid');
        $type = (string) $this->input->getOption('type');

        if ('' === $pid || '' === $type) {
            return $this->outputError('--pid and --type are required');
        }
        if (!ctype_digit($pid) || 0 === (int) $pid) {
            return $this->outputError(\sprintf('--pid must be a form ID, got: %s', $pid));
        }

        if (!isset($GLOBALS['TL_DCA']['tl_form_field']['fields'])) {
            Controller::loadDataContainer('tl_form_field');
        }
        $palettes = $GLOBALS['TL_DCA']['tl_form_field']['palettes'] ?? [];

        if (!isset($palettes[$type]) || !\is_string($palettes[$type])) {
            $known = array_values(array_filter(
                array_keys($palettes),
                static fn (string $k): bool => '__selector__' !== $k && 'default' !== $k,
            ));
            sort($known);

            return $this->outputError(\sprintf(
                'Unknown field type "%s". Available: %s',
                $type,
                implode(', ', $known),
            ));
        }

        $missing = $this->missingMandatoryFields('tl_form_field', $type, $fields, ['type']);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Field type "%s" also requires: %s. Pass them with --set. '
                . '(contao:form-field:types lists what each type needs.)',
                $type,
                implode(', ', $missing),
            ));
        }

        $fields = $this->convertFields('tl_form_field', $fields);

        $field          = new FormFieldModel();
        $field->tstamp  = time();
        $field->pid     = (int) $pid;
        $field->type    = $type;
        $field->sorting = $this->nextSorting((int) $pid);

        foreach ($fields as $key => $value) {
            $field->$key = $value;
        }

        $field->save();
        $this->createVersion('tl_form_field', (int) $field->id);

        $this->outputSuccess([
            'id'      => (int) $field->id,
            'pid'     => (int) $pid,
            'type'    => $type,
            'sorting' => (int) $field->sorting,
        ]);

        return Command::SUCCESS;
    }

    private function nextSorting(int $pid): int
    {
        $max = $this->connection->fetchOne(
            'SELECT MAX(sorting) FROM tl_form_field WHERE pid = ?',
            [$pid],
        );

        return (int) $max + self::SORTING_STEP;
    }
}
