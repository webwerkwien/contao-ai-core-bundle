<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ImageSizeItemModel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a media-query variant under an image size.
 *
 * This is where an image size stops being a single number. The parent carries
 * the fallback; each item says "at this media condition, use these dimensions
 * instead". A size with no items serves one variant to every viewport, which
 * is valid and often not what was wanted.
 *
 * `--media` is the CSS media condition, e.g. `(max-width: 767px)`. It has no
 * default: an item without one competes with the parent for every viewport,
 * and Contao's own back end presents it as the first thing to fill in.
 *
 * New items go to the end. Contao orders items by `sorting` and its back end
 * spaces them 128 apart so a later drag can land between two neighbours
 * without renumbering; appending follows that rather than inventing a scheme.
 */
#[AsCommand(name: 'contao:image-size-item:create', description: 'Create a media-query variant under an image size')]
class ImageSizeItemCreateCommand extends AbstractWriteCommand
{
    /** Contao's own gap between two adjacent sorting values. */
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
        $this->addOption('pid',   null, InputOption::VALUE_REQUIRED, 'Image size ID (tl_image_size) this variant belongs to');
        $this->addOption('media', null, InputOption::VALUE_REQUIRED, 'CSS media condition, e.g. "(max-width: 767px)"');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $pid   = (string) $this->input->getOption('pid');
        $media = (string) $this->input->getOption('media');

        if ('' === $pid || '' === $media) {
            return $this->outputError('--pid and --media are required');
        }
        if (!ctype_digit($pid) || 0 === (int) $pid) {
            return $this->outputError(\sprintf('--pid must be an image size ID, got: %s', $pid));
        }

        $item          = new ImageSizeItemModel();
        $item->tstamp  = time();
        $item->pid     = (int) $pid;
        $item->media   = $media;
        $item->sorting = $this->nextSorting((int) $pid);

        $fields = $this->convertFields('tl_image_size_item', $fields);

        foreach ($fields as $key => $value) {
            $item->$key = $value;
        }

        $item->save();
        $this->createVersion('tl_image_size_item', (int) $item->id);

        $this->outputSuccess([
            'id'      => (int) $item->id,
            'pid'     => (int) $pid,
            'media'   => $media,
            'sorting' => (int) $item->sorting,
        ]);

        return Command::SUCCESS;
    }

    private function nextSorting(int $pid): int
    {
        $max = $this->connection->fetchOne(
            'SELECT MAX(sorting) FROM tl_image_size_item WHERE pid = ?',
            [$pid],
        );

        return (int) $max + self::SORTING_STEP;
    }
}
