<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;

/**
 * Creates the back-end user `contao-ai` that DCA callbacks see on the console (v1.4.0).
 *
 * An admin that cannot log in: `disable` set and no password. Both are checked only by
 * Contao's UserChecker at login, which this user never goes through — CliUser puts it
 * into the token storage directly, for the duration of a callback run. Hidden and
 * locked in the back end by CliUserProtectionListener. See CliUser for why it exists.
 *
 * Runs once, through `contao:migrate` (also the Contao Manager's database update). A
 * user someone deleted comes back on the next migration, which is intended: without
 * it the callbacks run for nobody again.
 */
class CliUserMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'contao-ai: back-end user for DCA callbacks on the console';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_user'])) {
            return false;
        }

        if (false !== $this->connection->fetchOne('SELECT id FROM tl_user WHERE username = ?', [CliUser::USERNAME])) {
            return false;
        }

        // Not before the first real user (pre-release review M2). On a fresh install
        // contao:migrate runs before the administrator is created: contao-ai would get
        // id 1 — the user Contao and this bundle fall back to as author — and an
        // installer that looks for an existing admin would find this one. The next
        // migration after the first user creates it.
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_user') > 0;
    }

    public function run(): MigrationResult
    {
        $now = time();

        $row = [
            'tstamp'    => $now,
            'dateAdded' => $now,
            'username'  => CliUser::USERNAME,
            'name'      => 'contao-ai (CLI)',
            'email'     => '',
            'language'  => 'en',
            'password'  => '',
            'admin'     => 1,
            'disable'   => 1,
            'inherit'   => 'group',
        ];

        // Only the columns this installation has: tl_user grows between 5.3 and 6.0.
        $columns = array_map(
            static fn ($column): string => strtolower($column->getName()),
            $this->connection->createSchemaManager()->listTableColumns('tl_user'),
        );

        $this->connection->insert('tl_user', array_filter(
            $row,
            static fn (string $column): bool => \in_array(strtolower($column), $columns, true),
            \ARRAY_FILTER_USE_KEY,
        ));

        return $this->createResult(true, 'Created the back-end user "' . CliUser::USERNAME . '" (admin, cannot log in) for DCA callbacks on the console.');
    }
}
