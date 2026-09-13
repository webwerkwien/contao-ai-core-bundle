<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cache;

use Contao\Controller;
use Contao\DataContainer;
use Contao\DC_Table;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Invalidates the HTTP cache the way the back end does after a write.
 *
 * `DC_Table` calls `DataContainer::invalidateCacheTags()` after save, delete,
 * version restore and undo. Nothing in this bundle did until v0.10.0, and
 * `Contao\Model::save()` does not invalidate on its own — so every write through
 * contao-ai left cached pages as they were, and `/sitemap.xml` (cached for
 * thirty days, `s-maxage=2592000`) as it was for a month. Reported from the
 * Consho project on 2026-09-13.
 *
 * The tags are the ones `invalidateCacheTags()` builds, identical in Contao 5.3,
 * 5.7 and 6.0:
 *
 *  1. `contao.db.<table>.<id>`
 *  2. the parent's tag — `contao.db.<ptable>.<pid>`, where a tree table (mode 5)
 *     is its own ptable — or `contao.db.<table>` when there is no parent
 *  3. whatever the `oninvalidate_cache_tags_callback`s add. This is where
 *     `contao.sitemap.<root>` comes from, in tl_page, tl_news, tl_calendar,
 *     tl_calendar_events, tl_news_archive — and in every extension that puts
 *     addresses of its own into the sitemap.
 *
 * ⚠️ **The callbacks are the one kind of extension code this bundle runs on
 * purpose** (decided 2026-09-13). They are declared in the DCA and hand back a
 * list of tags; `save_callback` and friends still do not run. Without them the
 * sitemap would stay stale, which is the defect this class exists for.
 *
 * `DC_Table` cannot be constructed on the console — its constructor reads the
 * session and the request. The callbacks get an instance built without it,
 * carrying what they read in Contao's own code: `id`, `table` and
 * `activeRecord`. A callback that needs more (a request, a user, the voters
 * behind `getCurrentRecord()`) fails, is reported in `drainReport()` and does
 * not stop the others — the write it follows has already happened.
 */
class CacheTagInvalidator implements ResetInterface
{
    /** @var list<string> */
    private array $invalidated = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param object|null $tagManager   `contao.cache.tag_manager` — Contao 5.7 and 6.0
     * @param object|null $cacheManager `fos_http_cache.cache_manager` — what 5.3's
     *                                  DataContainer calls, as there is no tag manager yet
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?object $tagManager = null,
        private readonly ?object $cacheManager = null,
    ) {
    }

    /**
     * The tags a write to this record invalidates.
     *
     * Call it while the record still exists: for a delete, before deleting.
     * `DC_Table::delete()` does the same, and the callbacks look the record up.
     *
     * @return list<string>
     */
    public function collect(string $table, int $id): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table])) {
            try {
                Controller::loadDataContainer($table);
            } catch (\Throwable) {
                // No framework in a bare harness; the record tag still applies.
            }
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?',
            [$id],
        );
        $row = \is_array($row) ? $row : null;

        $dca    = $GLOBALS['TL_DCA'][$table] ?? [];
        $ptable = self::parentTable($table, $dca, $row);
        $pid    = (int) ($row['pid'] ?? 0);

        $tags   = ['contao.db.' . $table . '.' . $id];
        $tags[] = null !== $ptable && $pid > 0 ? 'contao.db.' . $ptable . '.' . $pid : 'contao.db.' . $table;

        $callbacks = $dca['config']['oninvalidate_cache_tags_callback'] ?? null;

        if (\is_array($callbacks) && [] !== $callbacks) {
            $dc = $this->dataContainer($table, $id, $row, $ptable);

            foreach ($callbacks as $callback) {
                try {
                    if (\is_array($callback)) {
                        $result = System::importStatic($callback[0])->{$callback[1]}($dc, $tags);
                    } elseif (\is_callable($callback)) {
                        $result = $callback($dc, $tags);
                    } else {
                        continue;
                    }

                    if (\is_array($result)) {
                        $tags = $result;
                    }
                } catch (\Throwable $e) {
                    $this->warnings[] = \sprintf(
                        '%s.%d: oninvalidate_cache_tags_callback %s failed, its tags were not invalidated: %s',
                        $table,
                        $id,
                        self::describe($callback),
                        $e->getMessage(),
                    );
                }
            }
        }

        return self::clean($tags);
    }

    /**
     * @param array<mixed> $tags
     */
    public function invalidate(array $tags): void
    {
        $tags = self::clean($tags);

        if ([] === $tags) {
            return;
        }

        $target = $this->tagManager ?? $this->cacheManager;

        if (null === $target || !method_exists($target, 'invalidateTags')) {
            $this->warnings[] = 'No cache invalidation service (contao.cache.tag_manager or '
                . 'fos_http_cache.cache_manager) is available; cached pages keep their old state '
                . 'until `cache:clear`.';

            return;
        }

        try {
            $target->invalidateTags($tags);
            $this->invalidated = self::clean([...$this->invalidated, ...$tags]);
        } catch (\Throwable $e) {
            $this->warnings[] = 'Cache tags could not be invalidated: ' . $e->getMessage();
        }
    }

    public function recordChanged(string $table, int $id): void
    {
        $this->invalidate($this->collect($table, $id));
    }

    /**
     * What happened since the last call, for the command's JSON answer.
     *
     * @return array{tags: list<string>, warnings: list<string>}
     */
    public function drainReport(): array
    {
        $report = ['tags' => $this->invalidated, 'warnings' => $this->warnings];
        $this->reset();

        return $report;
    }

    public function reset(): void
    {
        $this->invalidated = [];
        $this->warnings    = [];
    }

    /**
     * The parent table `addPtableTags()` sees in the back end.
     *
     * A tree (mode 5) is its own parent. Otherwise the DCA's `ptable` — which for
     * a `dynamicPtable` table like tl_content is not in the DCA at all: the
     * `DC_Table` constructor puts it there from `findPtable()`, and that reads the
     * record's own `ptable` column (5.3, 5.7, 6.0). The record wins over a value
     * a loadDataContainer listener left in the DCA, as it does there.
     *
     * @param array<string, mixed>      $dca
     * @param array<string, mixed>|null $row
     */
    private static function parentTable(string $table, array $dca, ?array $row): ?string
    {
        if (5 === (int) ($dca['list']['sorting']['mode'] ?? 0)) {
            return $table;
        }

        if (($dca['config']['dynamicPtable'] ?? false) && \is_string($row['ptable'] ?? null) && '' !== $row['ptable']) {
            return $row['ptable'];
        }

        $ptable = $dca['config']['ptable'] ?? null;

        return \is_string($ptable) && '' !== $ptable ? $ptable : null;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function dataContainer(string $table, int $id, ?array $row, ?string $ptable): DataContainer
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();

        $properties = [
            'intId'           => $id,
            'strTable'        => $table,
            'objActiveRecord' => null === $row ? null : (object) $row,
        ];

        foreach ($properties as $property => $value) {
            (new \ReflectionProperty(DataContainer::class, $property))->setValue($dc, $value);
        }

        // `$dc->ptable` — set by the DC_Table constructor from findPtable().
        if (property_exists(DC_Table::class, 'ptable')) {
            (new \ReflectionProperty(DC_Table::class, 'ptable'))->setValue($dc, $ptable);
        }

        return $dc;
    }

    /**
     * @param array<mixed> $tags
     *
     * @return list<string>
     */
    private static function clean(array $tags): array
    {
        return array_values(array_filter(
            array_unique(array_map(static fn (mixed $t): string => \is_scalar($t) ? (string) $t : '', $tags)),
            static fn (string $t): bool => '' !== $t,
        ));
    }

    private static function describe(mixed $callback): string
    {
        if (\is_array($callback)) {
            return implode('::', array_map(static fn (mixed $p): string => \is_object($p) ? $p::class : (string) $p, $callback));
        }

        return $callback instanceof \Closure ? 'closure' : get_debug_type($callback);
    }
}
