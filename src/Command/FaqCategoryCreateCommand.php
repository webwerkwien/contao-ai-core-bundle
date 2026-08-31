<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FaqCategoryModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create an FAQ category — the container a question lives in.
 *
 * The third of the same gap. `contao:faq:create` needed a `pid` that could not
 * be created.
 *
 * This one differs from the other two, and the difference is worth not
 * flattening: `tl_faq_category` has **no `protected` subpalette**, `headline`
 * is mandatory alongside `title`, and `jumpTo` sits in the palette **without**
 * being mandatory. So:
 *
 *   faq category-create --title "Support"                        → refused, headline missing
 *   faq category-create --title "Support" --set headline="FAQ"   → accepted, no jumpTo needed
 *
 * `title` is the back end label, `headline` the heading shown on the page.
 * Nothing here guesses one from the other — they are different texts as often
 * as they are the same, and inventing a default would put a back end label on
 * a public page.
 */
#[AsCommand(name: 'contao:faq-category:create', description: 'Create an FAQ category')]
class FaqCategoryCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Category title (back end label)');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = (string) $this->input->getOption('title');

        if ('' === $title) {
            return $this->outputError('--title is required');
        }

        if (!isset($GLOBALS['TL_DCA']['tl_faq_category']['fields'])) {
            Controller::loadDataContainer('tl_faq_category');
        }

        $missing = $this->missingMandatoryFields('tl_faq_category', 'default', $fields, ['title']);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Also required: %s. Pass them with --set. (headline is the heading shown '
                . 'on the page, as opposed to the back end label in --title.)',
                implode(', ', $missing),
            ));
        }

        $fields = $this->convertFields('tl_faq_category', $fields);

        $category         = new FaqCategoryModel();
        $category->tstamp = time();
        $category->title  = $title;

        foreach ($fields as $key => $value) {
            $category->$key = $value;
        }

        $category->save();
        $this->createVersion('tl_faq_category', (int) $category->id);

        $this->outputSuccess(['id' => (int) $category->id, 'title' => $title]);

        return Command::SUCCESS;
    }
}
