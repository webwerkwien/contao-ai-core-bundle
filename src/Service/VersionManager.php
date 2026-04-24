<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Service;

use Doctrine\DBAL\Connection;

class VersionManager
{
    private const ALLOWED_TABLES = [
        'tl_article', 'tl_calendar_events', 'tl_content', 'tl_faq',
        'tl_files', 'tl_layout', 'tl_member', 'tl_news', 'tl_page', 'tl_user',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function isAllowedTable(string $table): bool
    {
        return in_array($table, self::ALLOWED_TABLES, true);
    }

    public function createVersion(string $table, int $id): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `' . $table . '` WHERE id = ?', [$id]
        );
        if ($row === false) {
            return;
        }

        $this->connection->transactional(function () use ($table, $id, $row): void {
            $max = (int) $this->connection->fetchOne(
                'SELECT MAX(version) FROM tl_version WHERE fromTable = ? AND pid = ?',
                [$table, $id]
            );
            $this->connection->executeStatement(
                'UPDATE tl_version SET active = 0 WHERE fromTable = ? AND pid = ?',
                [$table, $id]
            );
            $this->connection->insert('tl_version', [
                'tstamp'    => time(),
                'fromTable' => $table,
                'pid'       => $id,
                'version'   => $max + 1,
                'username'  => $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'cli-agent',
                'active'    => 1,
                'data'      => serialize($row),
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
