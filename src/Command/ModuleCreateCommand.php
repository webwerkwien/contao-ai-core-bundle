<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a front end module under a theme.
 *
 * `tl_module` has 113 fields and twelve of them are marked mandatory, which
 * reads like an impossible command to call. It is not, because **a mandatory
 * field only applies to the module types whose palette contains it**. That is
 * not an interpretation: it is how `DC_Table` validates — it walks the active
 * palette, and a field outside it is never asked for. A navigation needs
 * `pages`; a news list needs `news_archives` and `numberOfItems`; a login
 * module needs neither and would refuse to be created if it did.
 *
 * So the requirement is computed from the DCA at runtime rather than kept as a
 * table here. Of the 45 types on a stock 5.7, 21 need nothing beyond a name and
 * 24 need something more. A second copy of that mapping would be a second thing
 * to maintain — and it would silently miss the module types a third-party
 * extension registers, which arrive with their own palettes and are covered by
 * this for free.
 *
 * A missing field is named rather than defaulted. Guessing which news archive
 * someone meant is worse than saying which option is missing.
 */
#[AsCommand(name: 'contao:module:create', description: 'Create a front end module under a theme')]
class ModuleCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('pid',  null, InputOption::VALUE_REQUIRED, 'Theme ID (tl_theme) the module belongs to');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module name shown in the back end');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Module type, e.g. "navigation", "newslist", "form"');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $pid  = (string) $this->input->getOption('pid');
        $name = (string) $this->input->getOption('name');
        $type = (string) $this->input->getOption('type');

        if ('' === $pid || '' === $name || '' === $type) {
            return $this->outputError('--pid, --name and --type are required');
        }
        if (!ctype_digit($pid) || 0 === (int) $pid) {
            return $this->outputError(\sprintf('--pid must be a theme ID, got: %s', $pid));
        }

        Controller::loadDataContainer('tl_module');
        $palettes = $GLOBALS['TL_DCA']['tl_module']['palettes'] ?? [];

        if (!isset($palettes[$type]) || !\is_string($palettes[$type])) {
            $known = array_values(array_filter(
                array_keys($palettes),
                static fn (string $k): bool => '__selector__' !== $k && 'default' !== $k,
            ));
            sort($known);

            return $this->outputError(\sprintf(
                'Unknown module type "%s". Available: %s',
                $type,
                implode(', ', $known),
            ));
        }

        $missing = $this->missingRequiredFields($type, $fields);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Module type "%s" also requires: %s. Pass them with --set.',
                $type,
                implode(', ', $missing),
            ));
        }

        $fields = $this->convertFields('tl_module', $fields);

        $module         = new ModuleModel();
        $module->tstamp = time();
        $module->pid    = (int) $pid;
        $module->name   = $name;
        $module->type   = $type;

        foreach ($fields as $key => $value) {
            $module->$key = $value;
        }

        $module->save();
        $this->createVersion('tl_module', (int) $module->id);

        $this->outputSuccess([
            'id'   => (int) $module->id,
            'name' => $name,
            'type' => $type,
            'pid'  => (int) $pid,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Which mandatory fields this type asks for and the caller did not supply.
     *
     * `name` is excluded because it has its own option. Everything else comes
     * from intersecting the DCA's mandatory fields with the type's palette —
     * the same set `DC_Table` would enforce in the back end form.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    public function missingRequiredFields(string $type, array $fields): array
    {
        // The palette key here is the module type — that is the whole point of
        // the rule. Since v0.2.22 the check itself lives on AbstractWriteCommand
        // and covers subpalettes too; module types use them as well
        // (`news_featured`, `cal_noSpan` and friends hang off selectors).
        return $this->missingMandatoryFields('tl_module', $type, $fields, ['name']);
    }
}
