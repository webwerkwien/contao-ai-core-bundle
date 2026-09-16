<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Doctrine\DBAL\Connection;

class VersionManager
{
    /**
     * Tables whose versions may be created and restored by name.
     *
     * Explicit on purpose — the name goes into SQL (security audit 2026-04-23). It
     * has to follow what the bundle versions, though: until v0.16.0 it still held the
     * ten tables of April while 22 were versioned, and `version restore --table
     * tl_image_size` answered "Table not allowed" for a version the bundle had just
     * written. VersionAllowListTest keeps the two in step.
     */
    private const ALLOWED_TABLES = [
        'tl_article', 'tl_calendar', 'tl_calendar_events', 'tl_content', 'tl_faq',
        'tl_faq_category', 'tl_files', 'tl_form', 'tl_form_field', 'tl_image_size',
        'tl_image_size_item', 'tl_layout', 'tl_member', 'tl_member_group', 'tl_module',
        'tl_news', 'tl_news_archive', 'tl_newsletter', 'tl_newsletter_channel',
        'tl_newsletter_recipients', 'tl_page', 'tl_theme', 'tl_user', 'tl_user_group',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function isAllowedTable(string $table): bool
    {
        return in_array($table, self::ALLOWED_TABLES, true);
    }

    /**
     * @param string|null $operator Audit-trail user identifier. When passed by the
     *  backend bundle this carries the Contao `tl_user.username`. CLI invocations
     *  fall back to `$_SERVER['USER']` so manual operator runs still get attributed.
     */
    /**
     * `tl_version.description` of the first version of a record this bundle created.
     *
     * Marks where the record's own history begins: a database can hand out the ID of a
     * deleted record again, and its versions stay under that ID (measured on c5 on
     * 2026-09-16, layout 25 with versions from 2026-08-31). See RecordIdReuseTest.
     */
    public const CREATED = 'created';

    /**
     * The first version of a record just created, marked as such.
     *
     * @return int the highest version number already under this ID (0 for none) — a previous record's
     */
    public function createInitialVersion(string $table, int $id, ?string $operator = null): int
    {
        $earlier = (int) $this->connection->fetchOne(
            'SELECT MAX(version) FROM tl_version WHERE fromTable = ? AND pid = ?',
            [$table, $id],
        );

        $this->insertVersion($table, $id, $operator, self::CREATED);

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

    public function createVersion(string $table, int $id, ?string $operator = null): void
    {
        $this->insertVersion($table, $id, $operator, null);
    }

    private function insertVersion(string $table, int $id, ?string $operator, ?string $description): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `' . $table . '` WHERE id = ?', [$id]
        );
        if ($row === false) {
            return;
        }

        $username = ($operator !== null && $operator !== '')
            ? $operator
            : ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent');

        $this->connection->transactional(function () use ($table, $id, $row, $username, $description): void {
            $max = (int) $this->connection->fetchOne(
                'SELECT MAX(version) FROM tl_version WHERE fromTable = ? AND pid = ?',
                [$table, $id]
            );
            $this->connection->executeStatement(
                'UPDATE tl_version SET active = 0 WHERE fromTable = ? AND pid = ?',
                [$table, $id]
            );
            $this->connection->insert('tl_version', [
                'tstamp'      => time(),
                'fromTable'   => $table,
                'pid'         => $id,
                'version'     => $max + 1,
                'username'    => $username,
                'description' => $description ?? '',
                'active'      => 1,
                'data'        => serialize($row),
            ]);
        });
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

    public function markActiveVersion(string $table, int $id, int $version): void
    {
        $this->connection->executeStatement(
            'UPDATE tl_version SET active = 0 WHERE fromTable = ? AND pid = ?',
            [$table, $id]
        );
        $this->connection->executeStatement(
            'UPDATE tl_version SET active = 1 WHERE fromTable = ? AND pid = ? AND version = ?',
            [$table, $id, $version]
        );
    }
}
