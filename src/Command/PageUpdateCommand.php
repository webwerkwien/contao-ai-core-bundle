<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\PageModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\Page\PageUrlGuard;

#[AsCommand(name: 'contao:page:update', description: 'Update a Contao page')]
class PageUpdateCommand extends AbstractModelUpdateCommand
{
    /** Fields of a root whose change moves every URL below it. */
    private const ROOT_URL_FIELDS = ['dns', 'urlPrefix', 'urlSuffix', 'type'];

    /** Fields that change the page's own URL. */
    private const PAGE_URL_FIELDS = ['alias', 'type', 'pid', 'requireItem', 'urlPrefix', 'urlSuffix', 'dns'];

    private ?PageUrlGuard $pageUrlGuard = null;

    protected function modelClass(): string { return PageModel::class; }
    protected function entityName(): string { return 'Page'; }

    #[Required]
    public function setPageUrlGuard(PageUrlGuard $pageUrlGuard): void
    {
        $this->pageUrlGuard = $pageUrlGuard;
    }

    /**
     * Write and check the URL rules in one transaction (v0.15.0).
     *
     * Until v0.14.0 `--set urlPrefix=en` produced a second root on the same domain and
     * prefix, and `--set alias=packages` a second page at the same URL — both refused
     * by Contao's back end. The checks run after the write because Contao's alias
     * check reads the stored record; a refusal rolls the write back, version and log
     * entry included. See PageUrlGuardTest.
     *
     * The root check runs only when a root field changes — as in Contao, which checks
     * on saving the prefix. Editing the title of a root that is already duplicated
     * stays possible; fixing its prefix is the way out.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<string>|null
     */
    protected function applyToRecord(int $id, array $fields): ?array
    {
        if (null === $this->pageUrlGuard) {
            return parent::applyToRecord($id, $fields);
        }

        $guard = $this->pageUrlGuard;

        $updated = $guard->transactional(function () use ($guard, $id, $fields): ?array {
            $updated = parent::applyToRecord($id, $fields);

            if (null === $updated) {
                return null;
            }

            if ([] !== array_intersect($updated, self::ROOT_URL_FIELDS)) {
                $guard->assertRootUnique($id);
                $guard->assertTree($id);
            }

            if ([] !== array_intersect($updated, self::PAGE_URL_FIELDS)) {
                $guard->assertAliases([$id]);
            }

            return $updated;
        });

        // Accepted, like the back end — but said, like the back end (Nr. 48, 2026-09-17).
        if (null !== $updated && [] !== array_intersect($updated, self::PAGE_URL_FIELDS)) {
            $this->routeConflicts = $guard->routeConflicts($id);
        }

        return $updated;
    }
}
