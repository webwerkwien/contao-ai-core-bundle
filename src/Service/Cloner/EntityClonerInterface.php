<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

/**
 * Server-side container-clone primitive. Implementors are tagged with
 * `contao_ai.entity_cloner` and consumed by RecordCloneCommand via
 * tagged-iterator injection. Each implementation supports exactly one
 * Contao container table (e.g. tl_news_archive, tl_calendar) and is
 * responsible for cloning the container record AND cascading its child
 * records (news, events, faq entries, ...) atomically inside one
 * Doctrine transaction.
 *
 * Rationale: pure LLM-orchestrated cloning produced N+1 tool-calls per
 * archive (one create + N reads + N creates), hitting the rate-limit and
 * bloating the context window. Macro-cloners shift the fan-out to the
 * server; the LLM only sees a single result row.
 */
interface EntityClonerInterface
{
    public function supports(string $table): bool;

    /**
     * @param int                          $sourceId      ID of the source container record
     * @param array<string, scalar|null>   $modifications Field overrides for the cloned root record (e.g. ['title' => 'Pressemitteilungen 2026'])
     * @param string                       $operator      Audit-trail user identifier (Contao username for backend, $_SERVER[USER] for CLI)
     *
     * @return array{id: int, table: string, count: int}  New root record id, source table name, number of cloned child records
     *
     * @throws \RuntimeException on missing source, transaction failure, or DCA violation
     */
    public function clone(int $sourceId, array $modifications, string $operator): array;
}
