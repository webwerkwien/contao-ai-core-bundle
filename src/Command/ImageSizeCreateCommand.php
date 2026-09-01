<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ImageSizeModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create an image size under a theme.
 *
 * `--pid` is a theme ID and is not optional: `tl_image_size.ptable` is
 * `tl_theme`, so a size that belongs to no theme is not a thing Contao has.
 *
 * Two fields decide more than their names suggest, and neither is `width`:
 * `sizes` is the media-condition list the browser evaluates to pick a variant,
 * and `densities` is the set of variants it may pick from. A size created with
 * `width` alone is valid and will quietly serve one variant to every viewport.
 * Both are plain columns, so they go in through `--set` like anything else.
 *
 * `preserveMetadataFields` is marked mandatory in the DCA but stands NULL in
 * every row Contao's own back end writes — the requirement only bites in the
 * form, and only once `preserveMetadata` asks for a field list. Nothing is
 * invented for it here: leaving it alone produces the same row the back end
 * produces. `preserveMetadata` itself fills in from its SQL default.
 */
#[AsCommand(name: 'contao:image-size:create', description: 'Create an image size under a theme')]
class ImageSizeCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('pid',  null, InputOption::VALUE_REQUIRED, 'Theme ID (tl_theme) the size belongs to');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name shown in the back end, e.g. "Tourenbild"');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $name = (string) $this->input->getOption('name');
        $pid  = (string) $this->input->getOption('pid');

        if ('' === $name || '' === $pid) {
            return $this->outputError('--name and --pid are required');
        }
        if (!ctype_digit($pid) || 0 === (int) $pid) {
            return $this->outputError(\sprintf('--pid must be a theme ID, got: %s', $pid));
        }

        $fields = $this->preparedFields('tl_image_size', [
            'pid'  => (int) $pid,
            'name' => $name,
        ], $fields);

        $size         = new ImageSizeModel();
        $size->tstamp = time();

        foreach ($fields as $key => $value) {
            $size->$key = $value;
        }

        $size->save();
        $this->createVersion('tl_image_size', (int) $size->id);

        $this->outputSuccess(['id' => (int) $size->id, 'name' => $name, 'pid' => (int) $pid]);

        return Command::SUCCESS;
    }
}
