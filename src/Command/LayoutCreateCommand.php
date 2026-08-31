<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\LayoutModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a page layout under a theme.
 *
 * `--template` has no default and is required, which is a decision rather than
 * an omission. The DCA marks it mandatory and offers no default of its own; its
 * options come from a callback that needs a live DataContainer, because what is
 * on offer depends on the record: a legacy layout gets the `fe_*` PHP template
 * group, a modern one gets the `page/layout` Twig templates found on disk
 * (ThemeLayoutListener::getTemplateOptions). A create command has no
 * DataContainer, so it cannot resolve that list — and guessing `fe_page`
 * because the demo install uses it would be inventing an answer for a question
 * only the caller can settle.
 *
 * What a created layout does *not* have is sections and modules. Both are
 * wizard columns holding serialized structures, and there is no sensible empty
 * default that is also useful — a layout without modules renders nothing. The
 * expectation is that they are filled in afterwards, in the back end or through
 * `--set` by a caller that knows the format.
 */
#[AsCommand(name: 'contao:layout:create', description: 'Create a page layout under a theme')]
class LayoutCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('pid',      null, InputOption::VALUE_REQUIRED, 'Theme ID (tl_theme) the layout belongs to');
        $this->addOption('name',     null, InputOption::VALUE_REQUIRED, 'Layout name');
        $this->addOption('template', null, InputOption::VALUE_REQUIRED, 'Layout template, e.g. "fe_page" for a legacy layout');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $pid      = (string) $this->input->getOption('pid');
        $name     = (string) $this->input->getOption('name');
        $template = (string) $this->input->getOption('template');

        if ('' === $pid || '' === $name || '' === $template) {
            return $this->outputError('--pid, --name and --template are required');
        }
        if (!ctype_digit($pid) || 0 === (int) $pid) {
            return $this->outputError(\sprintf('--pid must be a theme ID, got: %s', $pid));
        }

        // width, headerHeight and the other three inputUnit columns arrive as
        // plain numbers and have to be stored as {value, unit}; px is the unit
        // Contao offers first and the only one that makes sense unstated.
        $fields = $this->convertInputUnitFields('tl_layout', $fields, 'px');
        $fields = $this->convertFileTreeFields('tl_layout', $fields);

        $layout           = new LayoutModel();
        $layout->tstamp   = time();
        $layout->pid      = (int) $pid;
        $layout->name     = $name;
        $layout->template = $template;

        foreach ($fields as $key => $value) {
            $layout->$key = $value;
        }

        $layout->save();
        $this->createVersion('tl_layout', (int) $layout->id);

        $this->outputSuccess([
            'id'       => (int) $layout->id,
            'name'     => $name,
            'pid'      => (int) $pid,
            'template' => $template,
        ]);

        return Command::SUCCESS;
    }
}
