<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\StringUtil;

/**
 * An alias that is free, for the tables whose DCA says it has to be.
 *
 * 🔴 H-6 (Audit 2026-09-02). The cloners generated a child alias with
 * `StringUtil::generateAlias()` and saved it. `Contao\Model::save()` does not run
 * the DCA `save_callback`, so Contao's own uniqueness check never happened —
 * cloning a news item called *Sommerfest* produced a second row with the alias
 * `sommerfest`, and which of the two an alias-based lookup reaches is then a
 * matter of row order.
 *
 * ## Which tables, measured rather than assumed
 *
 * Contao 5.3 declares, for all three of `tl_news`, `tl_calendar_events` and
 * `tl_faq`:
 *
 *     'eval' => array('rgxp'=>'alias', 'doNotCopy'=>true, 'unique'=>true, …)
 *
 * `doNotCopy` is Contao saying outright that this value must not survive a
 * duplication — which is exactly what a clone is.
 *
 * ⚠️ **`tl_page` and `tl_article` are deliberately not in this set.** Their
 * aliases carry no `eval.unique`: a page alias may legitimately repeat across
 * roots, and Contao scopes that check by root and domain in its own callback.
 * `AbstractWriteCommand::resolveAlias()` already documents this, and suffixing
 * them here would rename a page for a clash that is not one.
 *
 * ## Why a counter and not a random suffix
 *
 * `PageCloner` appends `-kopie-<4 random hex>`, which collides only by accident
 * — but "only by accident" is not a check, and the result is unreadable. Contao
 * generates with an `$aliasExists` closure and lets the slug service count up.
 * This does the same thing with the tools a cloner has.
 */
trait GeneratesUniqueAlias
{
    /** A clone loop that cannot terminate is worse than a refused clone. */
    private const MAX_ALIAS_ATTEMPTS = 50;

    /**
     * @param string $table  table whose `alias` column must stay unique
     * @param string $from   human text the alias is derived from
     * @param string $prefix fallback when `$from` is empty
     */
    protected function uniqueAlias(string $table, string $from, string $prefix = 'kopie'): string
    {
        $base = StringUtil::generateAlias('' !== trim($from) ? $from : ($prefix . '-' . time()));

        // Kein eigener Schutz gegen rein numerische Aliases: `generateAlias()`
        // macht daraus bereits `id-2026`. Eine Abfrage hier hätte nie gefeuert
        // — und eine Prüfung, die nie greift, behauptet einen Schutz, den es
        // nicht gibt. (Aufgefallen, weil der Test sie erwartet hat und Contao
        // ihm zuvorkam.)

        $alias    = $base;
        $attempt  = 2;
        $quoted   = $this->connection->quoteIdentifier($table);

        while ($this->aliasTaken($quoted, $alias)) {
            if ($attempt > self::MAX_ALIAS_ATTEMPTS) {
                // Deterministic suffixes exhausted — fall back to something that
                // cannot collide rather than looping or writing a duplicate.
                return $base . '-' . substr(md5(uniqid('', true)), 0, 8);
            }

            $alias = $base . '-' . $attempt++;
        }

        return $alias;
    }

    private function aliasTaken(string $quotedTable, string $alias): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT id FROM ' . $quotedTable . ' WHERE alias = ? LIMIT 1',
            [$alias],
        );
    }
}
