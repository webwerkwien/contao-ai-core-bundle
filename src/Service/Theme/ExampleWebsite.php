<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Theme;

use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Theme;
use Contao\ZipReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;

/**
 * An example website (`.sql`) played in as the theme manager does (v1.9.0).
 *
 * Contao offers three ways, in its import form next to the theme archives:
 *
 * - `full`: every statement of the file, DROP and CREATE included
 * - `data`: every `tl_` table emptied (TRUNCATE), then only the INSERTs
 * - `data_no_truncate`: only the INSERTs, the data that is there stays
 *
 * The statements run through Contao's own `Theme::importExampleWebsite()`. Around it,
 * what the console needs and the back end gets from its situation:
 *
 * - a database backup first, always — the restore is one `backup restore` away
 * - the administrators: Contao restores the logged-in user if `tl_user` ends up empty;
 *   on the console that user is `contao-ai`, who cannot log in. So the existing admins are
 *   kept aside, and when the file brings users the caller decides (Michael, 2026-10-15):
 *   `both` keeps the existing admins next to the imported ones, `existing` keeps only the
 *   existing ones and removes imported admins. On the same username the existing account
 *   wins. Without existing admins the imported ones simply arrive. `contao-ai` is always
 *   put back.
 */
class ExampleWebsite
{
    public const MODES = ['full', 'data', 'data_no_truncate'];

    public const ADMIN_POLICIES = ['both', 'existing'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly DcaCallbacks $dcaCallbacks,
        #[Autowire(service: 'contao.doctrine.backup_manager')]
        private readonly BackupManager $backups,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * What an import would do.
     *
     * @return array{file: string, mode: string, statements: int, tables: list<string>, truncates: int, user_rows: int, existing_admins: list<string>, needs_admin_decision: bool}
     */
    public function analyse(string $path, string $mode, ?string $entry = null): array
    {
        $this->framework->initialize();
        self::assertMode($mode);
        $sql = $this->sqlFile($path, $entry);

        $statements = $this->statements($sql, $mode);
        $tables = [];
        $userRows = 0;
        foreach ($statements as $statement) {
            if (preg_match('/^\s*(?:INSERT|REPLACE)\s+INTO\s+`?(tl_[a-z0-9_]+)`?/i', $statement, $m)) {
                $tables[$m[1]] = $m[1];
                if ('tl_user' === $m[1]) {
                    $userRows += max(1, substr_count($statement, '),('));
                }
            } elseif (preg_match('/^\s*(?:CREATE|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(tl_[a-z0-9_]+)`?/i', $statement, $m)) {
                $tables[$m[1]] = $m[1];
            }
        }
        ksort($tables);

        $admins = array_column($this->existingAdmins(), 'username');

        return [
            'file'                 => Path::makeRelative($sql, $this->projectDir),
            'mode'                 => $mode,
            'statements'           => \count($statements),
            'tables'               => array_values($tables),
            'truncates'            => 'data' === $mode ? \count($this->tlTables()) : 0,
            'user_rows'            => $userRows,
            'existing_admins'      => array_map('strval', $admins),
            'needs_admin_decision' => [] !== $admins && $userRows > 0,
        ];
    }

    /**
     * @return array{backup: string, backup_note: string, statements: int, admins: array{kept: list<string>, removed: list<string>, imported: list<string>}, messages: list<array{type: string, text: string}>}
     */
    public function import(string $path, string $mode, ?string $adminPolicy, ?string $entry = null): array
    {
        $preview = $this->analyse($path, $mode, $entry);
        if ($preview['needs_admin_decision'] && !\in_array($adminPolicy, self::ADMIN_POLICIES, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'The file brings back-end users and there are administrators already (%s). Decide with --admins=both (keep them next to the imported ones) or --admins=existing (keep only them).',
                implode(', ', $preview['existing_admins']),
            ));
        }

        // A backup first, always: this replaces the site.
        $config = $this->backups->createCreateConfig();
        $this->backups->create($config);
        $backup = $config->getBackup()->getFilename();

        $kept = $this->existingAdmins();
        $cliUser = $this->connection->fetchAssociative('SELECT * FROM tl_user WHERE username = ?', [CliUser::USERNAME]) ?: null;
        $relative = $preview['file'];

        $this->dcaCallbacks->inBackEnd(static function () use ($relative, $mode): void {
            // Contao's own statement runner; private, so called bound to the class.
            $theme = new Theme();
            \Closure::bind(
                fn () => $this->importExampleWebsite($relative, 'data_no_truncate' === $mode, 'full' !== $mode),
                $theme,
                Theme::class,
            )();
        });

        $admins = $this->settleAdmins($kept, $preview['needs_admin_decision'] ? (string) $adminPolicy : 'both');
        if (null !== $cliUser) {
            $this->putBack($cliUser);
        }

        return [
            'backup'     => $backup,
            // Contao's retention (`contao.backup.keep_intervals`) keeps one backup per day
            // by default: the next backup made the same day removed this one (measured on
            // c5, 6.0, 2026-10-15, after two imports in a row).
            'backup_note' => 'This backup is the way back. Contao keeps one backup per interval: the next backup made today (another import, backup create, migrate) can remove it — restore first, or copy it out of var/backups.',
            'statements' => $preview['statements'],
            'admins'     => $admins,
            'messages'   => $this->dcaCallbacks->drainMessages(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $kept the admin rows from before
     *
     * @return array{kept: list<string>, removed: list<string>, imported: list<string>}
     */
    private function settleAdmins(array $kept, string $policy): array
    {
        $keptNames = array_map('strval', array_column($kept, 'username'));
        $imported  = array_values(array_diff(array_column($this->existingAdmins(), 'username'), $keptNames));
        $removed   = [];

        if ('existing' === $policy) {
            foreach ($imported as $username) {
                $this->connection->delete('tl_user', ['username' => $username]);
                $removed[] = (string) $username;
            }
            $imported = [];
        }

        // On the same username the existing account wins.
        foreach ($kept as $row) {
            $this->connection->delete('tl_user', ['username' => $row['username']]);
            $this->putBack($row);
        }

        return ['kept' => $keptNames, 'removed' => $removed, 'imported' => array_map('strval', $imported)];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function putBack(array $row): void
    {
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_user WHERE username = ?', [$row['username']]) > 0) {
            return;
        }

        // Its id may belong to an imported user by now: then it gets a new one.
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_user WHERE id = ?', [$row['id']]) > 0) {
            unset($row['id']);
        }

        // Only the columns the table still has: a `full` import may have recreated it.
        $columns = array_map(static fn ($c): string => $c->getName(), $this->connection->createSchemaManager()->listTableColumns('tl_user'));
        $row = array_intersect_key($row, array_flip($columns));

        $data = [];
        foreach ($row as $column => $value) {
            $data[$this->connection->quoteIdentifier((string) $column)] = $value;
        }
        $this->connection->insert('tl_user', $data);
    }

    /**
     * Administrators who can log in — `contao-ai` is one by flag but not by nature.
     *
     * @return list<array<string, mixed>>
     */
    private function existingAdmins(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_user WHERE admin = 1 AND username <> ? ORDER BY id',
            [CliUser::USERNAME],
        );
    }

    /**
     * The statements Contao runs: one per line, only INSERTs unless `full`.
     *
     * @return list<string>
     */
    private function statements(string $sql, string $mode): array
    {
        $lines = array_values(array_filter(
            (array) file($sql, FILE_IGNORE_NEW_LINES),
            static fn ($line): bool => '' !== trim((string) $line),
        ));

        return 'full' === $mode ? array_map('strval', $lines) : array_values(array_map('strval', preg_grep('/^INSERT /', $lines) ?: []));
    }

    /**
     * @return list<string>
     */
    private function tlTables(): array
    {
        return array_values(array_filter(
            $this->connection->createSchemaManager()->listTableNames(),
            static fn (string $t): bool => str_starts_with($t, 'tl_'),
        ));
    }

    /**
     * The .sql to run: the file itself, or the one inside a theme archive (where Contao's
     * archives carry it: templates/ or var/backups/), extracted next to it.
     */
    private function sqlFile(string $path, ?string $entry): string
    {
        $absolute = Path::canonicalize(Path::makeAbsolute($path, $this->projectDir));
        $tmp      = Path::join($this->projectDir, ThemeArchive::TMP_DIR);

        if (!Path::isBasePath($tmp, $absolute)) {
            throw new \InvalidArgumentException(\sprintf('The file must lie in %s/ (contao-ai-cli uploads it there): %s', ThemeArchive::TMP_DIR, $path));
        }
        if (!is_file($absolute)) {
            throw new \InvalidArgumentException(\sprintf('No such file: %s', $path));
        }
        if (preg_match('/\.sql$/i', $absolute)) {
            return $absolute;
        }
        if (!preg_match('/\.(cto|zip)$/i', $absolute)) {
            throw new \InvalidArgumentException(\sprintf('Not an example website (.sql, or a .cto/.zip holding one): %s', basename($absolute)));
        }

        $zip = new ZipReader(Path::makeRelative($absolute, $this->projectDir), true);
        $found = [];
        while ($zip->next()) {
            if (preg_match('#^(templates|var/backups)/.+\.sql$#i', (string) $zip->file_name)) {
                $found[(string) $zip->file_name] = $zip->unzip();
            }
        }

        if ([] === $found) {
            throw new \InvalidArgumentException(\sprintf('%s holds no example website (.sql in templates/ or var/backups/).', basename($absolute)));
        }
        if (null === $entry && \count($found) > 1) {
            throw new \InvalidArgumentException('The archive holds several example websites; name one with --entry: ' . implode(', ', array_keys($found)));
        }

        $name = $entry ?? (string) array_key_first($found);
        if (!isset($found[$name])) {
            throw new \InvalidArgumentException(\sprintf('No %s in the archive; it holds %s.', $name, implode(', ', array_keys($found))));
        }

        $target = Path::join($tmp, 'contao-ai-example-' . basename($name));
        file_put_contents($target, $found[$name]);

        return $target;
    }

    private static function assertMode(string $mode): void
    {
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('--mode is one of full, data, data_no_truncate — it decides whether existing data is replaced, emptied or kept; there is no default.');
        }
    }
}
