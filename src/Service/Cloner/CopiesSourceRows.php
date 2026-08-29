<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

/**
 * Shared source-row handling for the entity cloners.
 *
 * **Why this exists — do not go back to Model::row().** Doctrine DBAL maps
 * every MySQL `tinyint` column to `Types::BOOLEAN`, regardless of the declared
 * length (`AbstractMySQLPlatform`: `'tinyint' => Types::BOOLEAN`). Contao's
 * `Model::convertToPhpValue()` honours that mapping on read, so a stored `2`
 * arrives in `Model::row()` as `true`. Assigning that to a clone and saving it
 * writes `1` back — `Database\Statement::set()` binds PHP booleans as
 * `ParameterType::BOOLEAN`. A stored `0` survives only because `false` casts
 * to `0`, which is why the damage looked like "every value >= 1 becomes 1".
 *
 * Found on 2026-08-29 by cloning a tour page: walking time, difficulty,
 * severity, driving time and participant cap — the entire fact table — all
 * came out as 1, with the command reporting `status: ok`. `smallint` and
 * `varchar` columns were untouched, which is what pointed at the type mapping.
 *
 * Stock Contao lives with the same constraint and sidesteps it the same way: the back
 * end never reads a record through the model layer. `DataContainer::preloadCurrentRecords()`
 * runs a plain `SELECT *` over DBAL, and `DC_Table::copy()` takes its source row from
 * there via `getCurrentRecord()` — which is why copying inside the back end was never
 * affected by this, and why `convertToPhpValue()` is called from `Contao\Model` and
 * `Contao\User` and nowhere else.
 *
 * Worth knowing for anyone tempted to "fix" this further up: mapping `tinyint` to
 * boolean is Contao's convention, not a defect. Its own DCA uses `smallint(5)` and
 * `int(10)` for numbers and reserves `tinyint` for flags, and the cast table is built
 * from the DCA (`ContaoCacheWarmer::generateColumnCastTypes()`), not from the live
 * schema. A column that stores a number and is declared `tinyint` is mis-declared —
 * the place to correct that is the DCA of the project that owns the column, not here.
 * This trait exists because a cloner must reproduce stored values whatever their type.
 *
 * Consumers must expose a `Doctrine\DBAL\Connection $connection` property.
 */
trait CopiesSourceRows
{
    /**
     * Read a record straight from the database, bypassing the model layer's
     * type conversion.
     *
     * @return array<string, mixed> The row exactly as stored
     *
     * @throws \RuntimeException when the record no longer exists
     */
    protected function fetchSourceRow(string $table, int $id): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?',
            [$id],
        );

        if (!\is_array($row)) {
            throw new \RuntimeException(\sprintf('Datensatz %d in %s nicht gefunden.', $id, $table));
        }

        return $row;
    }

    /**
     * Copy every stored value onto the clone, except the primary key.
     *
     * @param array<string, mixed> $row
     */
    protected function copySourceRow(object $clone, array $row): void
    {
        foreach ($row as $key => $value) {
            if ('id' === $key) {
                continue;
            }

            $clone->$key = $value;
        }
    }
}
