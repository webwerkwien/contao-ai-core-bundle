<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\Page\PageUrlGuard;

#[AsCommand(name: 'contao:page:create', description: 'Create a Contao page')]
class PageCreateCommand extends AbstractWriteCommand
{
    private ?PageUrlGuard $pageUrlGuard = null;

    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    #[Required]
    public function setPageUrlGuard(PageUrlGuard $pageUrlGuard): void
    {
        $this->pageUrlGuard = $pageUrlGuard;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title',    null, InputOption::VALUE_REQUIRED, 'Page title');
        $this->addOption('type',     null, InputOption::VALUE_OPTIONAL, 'Page type (regular, root, …)', 'regular');
        $this->addOption('pid',      null, InputOption::VALUE_OPTIONAL, 'Parent page ID', 0);
        $this->addOption('alias',    null, InputOption::VALUE_OPTIONAL, 'Page alias (auto-generated if omitted)', '');
        $this->addOption('language', null, InputOption::VALUE_OPTIONAL, 'Page language', 'de');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = $this->input->getOption('title');
        if (!$title) {
            return $this->outputError('--title is required');
        }

        $fields = $this->preparedFields('tl_page', [
            'pid'      => (int) $this->input->getOption('pid'),
            // Behind the last sibling, roots included (pid 0). Was 0 until v0.13.0.
            'sorting'  => $this->nextSorting('tl_page', (int) $this->input->getOption('pid')),
            'title'    => $title,
            'type'     => $this->input->getOption('type'),
            'language' => $this->input->getOption('language'),
            // A given alias is taken as is. A missing one is Contao's, generated after
            // the write below — PageUrlListener::generateAlias() needs the saved page.
            // Without the guard (unit tests) the old slug remains.
            'alias'    => null === $this->pageUrlGuard || '' !== (string) $this->input->getOption('alias')
                ? $this->resolveAlias('tl_page', (string) $this->input->getOption('alias'), $title)
                : '',
            'cuser'    => $this->resolveAuthorId(),
            // cgroup deliberately left at 0 — Contao's chmod system treats 0 as
            // "no group ownership"; setting an arbitrary group could grant or
            // deny access incorrectly. Admins can adjust via the regular backend
            // module if a group ACL is needed.
            'cgroup'    => 0,
            'published' => '0',
        ], $fields);

        // Write and check the URL rules in one transaction (v0.15.0): a second root on
        // the same domain and prefix, or a page at a URL that is taken, is rolled back
        // — Contao refuses both in the back end. See PageUrlGuardTest.
        $write = function () use ($fields): PageModel {
            $page         = new PageModel();
            $page->tstamp = time();

            foreach ($fields as $key => $value) {
                $page->$key = $value;
            }
            $page->save();

            // Contao's alias, as the back end makes it when the field is left blank:
            // from the title, with the root's language and validAliasCharacters, unique
            // for the URL. Until v0.16.0 `über-uns` where Contao makes `ueber-uns`
            // (review 2026-09-16, Nr. 45). generateAlias() detaches the instance it
            // looks up, so the alias is saved through a fresh one, as in PageCloner.
            if (null !== $this->pageUrlGuard && '' === (string) $page->alias) {
                $alias = $this->pageUrlGuard->generateAlias((int) $page->id);
                $page  = PageModel::findByPk((int) $page->id) ?? throw new \RuntimeException('The new page vanished before its alias could be saved.');
                $page->alias = $alias;
                $page->save();
            }

            $this->createVersion('tl_page', (int) $page->id, created: true);

            if (null !== $this->pageUrlGuard) {
                $this->pageUrlGuard->assertRootUnique((int) $page->id);
                $this->pageUrlGuard->assertAliases([(int) $page->id]);
            }

            return $page;
        };

        $page = null === $this->pageUrlGuard ? $write() : $this->pageUrlGuard->transactional($write);

        if (null !== $this->pageUrlGuard) {
            $this->routeConflicts = $this->pageUrlGuard->routeConflicts((int) $page->id);
        }

        $this->outputSuccess(['id' => (int) $page->id, 'title' => $page->title, 'alias' => $page->alias]);
        return Command::SUCCESS;
    }
}
