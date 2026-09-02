<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\ContentModel;

/**
 * Clones the `tl_content` subtree hanging under a record.
 *
 * 🔴 H-5 (Audit 2026-09-02). `PageCloner` did this and the other cloners did
 * not — so `contao:record:clone` on a news archive or a calendar copied the
 * archive and its news, left every content element behind, counted only the
 * news and answered `ok`. The command description says *"including all
 * cascading children"*.
 *
 * 🎯 The evidence was in this bundle's own prose. `RecordCascadeCollector`
 * states it plainly: *"`dynamicPtable` children are matched on pid and ptable —
 * **tl_content hangs under articles, news and events**, and nests inside
 * itself."* Deleting a news item therefore takes its content with it; cloning
 * one did not bring it along. One half of the pair knew, the other did not.
 *
 * The logic lives here rather than being copied a third time. Earlier the same
 * day a table-to-module map turned out to exist in three places, two of which
 * were the same thing and one of which only looked like it — the cost of
 * finding that out is why this is a trait.
 *
 * Requires `CopiesSourceRows` and a `$versionManager` on the using class.
 */
trait ClonesContentSubtree
{
    /**
     * Clone every content element under `$sourceParentId` and, recursively,
     * everything nested inside those.
     *
     * @param string $parentTable `ptable` of the direct children — `tl_article`,
     *   `tl_news`, `tl_calendar_events`. Nested levels always use `tl_content`,
     *   which is how accordion and colset layouts reference their outer element.
     *
     * @return int number of content rows created
     */
    protected function cloneContentSubtree(
        int $sourceParentId,
        int $newParentId,
        string $parentTable,
        string $operator,
    ): int {
        $children = ContentModel::findBy(
            ['pid=?', 'ptable=?'],
            [$sourceParentId, $parentTable],
        );

        if (null === $children) {
            return 0;
        }

        $count = 0;

        foreach ($children as $source) {
            $newId = $this->cloneContentRow($source, $newParentId);
            $this->versionManager->createVersion('tl_content', $newId, $operator);
            ++$count;

            $count += $this->cloneContentSubtree((int) $source->id, $newId, 'tl_content', $operator);
        }

        return $count;
    }

    /**
     * The `ptable` is not set here: it comes along in the copied source row, so
     * a news content element stays `tl_news` and a nested one stays
     * `tl_content`. Setting it explicitly would be a second place to get it
     * wrong.
     */
    protected function cloneContentRow(ContentModel $source, int $newPid): int
    {
        $clone = new ContentModel();
        $this->copySourceRow($clone, $this->fetchSourceRow('tl_content', (int) $source->id));
        $clone->tstamp = time();
        $clone->pid    = $newPid;

        // Clones stay invisible until an operator has looked at them — the same
        // rule the page cloner has applied since it was written.
        $clone->invisible = '1';

        $clone->save();

        return (int) $clone->id;
    }
}
