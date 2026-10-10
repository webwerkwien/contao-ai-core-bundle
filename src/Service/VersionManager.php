<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Contao\Controller;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoAiCoreBundle\Command\MissingBundleHint;

/**
 * Versions of records, written and restored by Contao's own `Versions` class.
 *
 * 🎯 **Until v1.4.0 this class wrote `tl_version` with its own SQL**, and the copy had
 * drifted from Contao in five places: no `oncreate_version`/`onrestore_version`
 * callbacks, a restore that also overwrote `pid`, `ptable` and `sorting`, no
 * `versionize: false`, no reset of a `unique` value that is taken by now, and no
 * default for a column added after the version was stored. The callbacks are where
 * an extension keeps what belongs to a version but not to the row — Consho stores
 * the translations of a product there. A version written past them looks complete
 * and is not; restoring it brings back half a record.
 *
 * Contao's class was dropped once already (April 2026) because it "requires an
 * authenticated backend session". It does not: it asks for a user only to name him,
 * and `setUsername()`/`setUserId()` name him from outside. `create()` and `restore()`
 * are the same code in 5.3, 5.7 and 6.0 (compared on c5 on 2026-10-10).
 *
 * The timing follows Contao too: `initialize()` before a write keeps the state about
 * to be replaced if the record has no version yet, `create()` after it records the
 * new state — DC_Table::edit() does exactly that. The newest version is therefore
 * the current state, and undoing a write means restoring the one before it.
 *
 * What stays ours: the `created` marker on the first version of a record this bundle
 * created, and the refusal to restore a version of an earlier record with the same ID.
 */
class VersionManager
{
    /**
     * `tl_version.description` of the first version of a record this bundle created.
     *
     * Marks where the record's own history begins: a database can hand out the ID of a
     * deleted record again, and its versions stay under that ID (measured on c5 on
     * 2026-09-16, layout 25 with versions from 2026-08-31). See RecordIdReuseTest.
     */
    public const CREATED = 'created';

    /** @var \Closure(string, int): Versions */
    private readonly \Closure $versions;

    /** @var \Closure(string): void */
    private readonly \Closure $loadDataContainer;

    /**
     * @param (\Closure(string, int): Versions)|null $versions          only for tests
     * @param (\Closure(string): void)|null          $loadDataContainer only for tests
     */
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%contao.backend.route_prefix%')]
        private readonly string $backendPrefix = '/contao',
        ?\Closure $versions = null,
        ?\Closure $loadDataContainer = null,
    ) {
        $this->versions          = $versions ?? static fn (string $table, int $id): Versions => new Versions($table, $id);
        $this->loadDataContainer = $loadDataContainer ?? static function (string $table): void {
            Controller::loadDataContainer($table);
        };
    }

    public function isAllowedTable(string $table): bool
    {
        return null === $this->refusal($table);
    }

    /**
     * Why versions of $table can neither be created nor restored, or null when they can.
     *
     * Any table whose DCA keeps versions — the bundle's own, a core bundle's, an
     * extension's. It used to be a fixed list of 24, because the name goes into SQL
     * (security audit 2026-04-23); the name is still checked as a name before anything
     * else, and the table has to exist. Needs the framework initialised.
     */
    public function refusal(string $table): ?string
    {
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
            return "Invalid table name: {$table}";
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['config'])) {
            ($this->loadDataContainer)($table);
        }

        if (!isset($GLOBALS['TL_DCA'][$table]['config'])) {
            $hint = MissingBundleHint::for($table);

            return "{$table} has no DCA, so it has no versions." . ($hint ? "\n" . $hint : '');
        }

        if (!($GLOBALS['TL_DCA'][$table]['config']['enableVersioning'] ?? false)) {
            return "{$table} keeps no versions: enableVersioning is off in its DCA, in the back end as well.";
        }

        if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
            return "{$table} has a DCA but no database table.";
        }

        return null;
    }

    /**
     * Before a write: keep the current state, if the record has no version yet.
     *
     * Contao's `initialize()`, which stores that version without a user, as the back end
     * does when a record is opened for the first time.
     */
    public function initialize(string $table, int $id): void
    {
        ($this->versions)($table, $id)->initialize();
    }

    /**
     * After a write: record the new state.
     *
     * @param string|null $operator Audit-trail user identifier. When passed by the
     *  backend bundle this carries the Contao `tl_user.username`. CLI invocations
     *  fall back to `$_SERVER['USER']` so manual operator runs still get attributed.
     */
    public function createVersion(string $table, int $id, ?string $operator = null): void
    {
        $username = (null !== $operator && '' !== $operator)
            ? $operator
            : ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent');

        $versions = ($this->versions)($table, $id);
        $versions->setUsername($username);
        $versions->setUserId($this->userIdOf($username));
        // Never the current request: from the chat that is the chat's own endpoint,
        // and on the console there is none (getEditUrl() would read a null request).
        $versions->setEditUrl($this->editUrl($table, $id));
        $versions->create();
    }

    /**
     * The first version of a record just created, marked as such.
     *
     * @return int the highest version number already under this ID (0 for none) — a previous record's
     */
    public function createInitialVersion(string $table, int $id, ?string $operator = null): int
    {
        $earlier = $this->latestVersion($table, $id);

        $this->createVersion($table, $id, $operator);

        $created = $this->latestVersion($table, $id);

        // Contao writes nothing for a table without versioning or a row without tstamp;
        // then there is no first version to mark.
        if ($created > $earlier) {
            $this->connection->executeStatement(
                'UPDATE tl_version SET description = ? WHERE fromTable = ? AND pid = ? AND version = ?',
                [self::CREATED, $table, $id, $created],
            );
        }

        return $earlier;
    }

    /**
     * Whether a version predates the creation of the current record with this ID.
     *
     * False without a creation marker — a record created in the back end, or before
     * v0.16.0 — because nothing is known then.
     */
    public function belongsToEarlierRecord(string $table, int $id, int $version): bool
    {
        $creation = $this->connection->fetchOne(
            'SELECT MAX(version) FROM tl_version WHERE fromTable = ? AND pid = ? AND description = ?',
            [$table, $id, self::CREATED],
        );

        return false !== $creation && null !== $creation && $version < (int) $creation;
    }

    /**
     * Restore a version through Contao, all or nothing.
     *
     * `Versions::restore()` reports a failed UPDATE with a flash message and a reload —
     * neither exists on the console, and in the chat the message would surface on the
     * user's next page. Whatever it throws ends up here, the transaction is rolled back
     * (an `onrestore_version` callback that fails leaves nothing half done), and the
     * caller gets one exception naming the version.
     *
     * It also returns silently when it finds nothing to do. The callers check table and
     * version beforehand; the active flag afterwards proves that it really ran.
     *
     * @throws \RuntimeException when the version was not restored
     */
    public function restore(string $table, int $id, int $version): void
    {
        try {
            $this->connection->transactional(function () use ($table, $id, $version): void {
                ($this->versions)($table, $id)->restore($version);

                $active = $this->connection->fetchOne(
                    'SELECT active FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?',
                    [$table, $id, $version],
                );

                if (1 !== (int) $active) {
                    throw new \RuntimeException('Contao did not restore it.');
                }
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                \sprintf('Version %d of %s:%d was not restored, nothing was changed: %s', $version, $table, $id, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Returns unserialized version data or false if not found/corrupt.
     * Uses allowed_classes:false to prevent PHP object injection via POP chains.
     */
    public function loadVersionData(string $table, int $id, int $version): array|false
    {
        $row = $this->connection->fetchAssociative(
            'SELECT data FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?',
            [$table, $id, $version]
        );
        if ($row === false) {
            return false;
        }
        $data = unserialize($row['data'], ['allowed_classes' => false]);
        return is_array($data) ? $data : false;
    }

    /** The highest version number under this record, 0 for none. */
    public function latestVersion(string $table, int $id): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT MAX(version) FROM tl_version WHERE fromTable = ? AND pid = ?',
            [$table, $id],
        );
    }

    private function userIdOf(string $username): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM tl_user WHERE username = ?', [$username]);
    }

    /**
     * The edit URL in the form the back end stores it: `contao?do=group&act=edit&id=1`,
     * with `table=` when the table is not the first of its module
     * (`contao?do=article&table=tl_content&act=edit&id=642`, read on c5). Empty when no
     * back-end module lists the table — the back end has nothing to link to either.
     */
    private function editUrl(string $table, int $id): string
    {
        foreach ($GLOBALS['BE_MOD'] ?? [] as $modules) {
            foreach ((array) $modules as $do => $module) {
                $tables = array_values((array) ($module['tables'] ?? []));

                if (!\in_array($table, $tables, true)) {
                    continue;
                }

                $query = ['do' => $do];

                if ($tables[0] !== $table) {
                    $query['table'] = $table;
                }

                return ltrim($this->backendPrefix, '/') . '?' . http_build_query($query + ['act' => 'edit', 'id' => $id], '', '&', \PHP_QUERY_RFC3986);
            }
        }

        return '';
    }
}
