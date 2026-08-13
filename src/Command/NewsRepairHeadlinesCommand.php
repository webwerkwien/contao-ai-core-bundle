<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Repairs `tl_news.headline` values that were stored as a serialized
 * {value, unit} array instead of plain text.
 *
 * Background: up to and including v0.2.3, NewsCreateCommand wrote
 * `serialize(['value' => …, 'unit' => …])` into tl_news.headline. That column is
 * a plain `varchar(255)` text field (Contao DCA `inputType => 'text'`) holding
 * the news *title*; Contao renders it verbatim, so affected records show the raw
 * `a:2:{s:5:"value";…}` string in the back end listing, the RSS feed and the
 * front end. Only tl_content.headline is a real `inputUnit` field.
 *
 * The conversion is loss-free in practice: the `unit` part carries no meaning
 * for tl_news, so only the `value` is kept. Records that do not deserialize into
 * an array with a `value` key are left untouched, which makes the command safe
 * to run repeatedly and on already-clean installations.
 */
#[AsCommand(
    name: 'contao:news:repair-headlines',
    description: 'Repair tl_news.headline values wrongly stored as serialized {value, unit} arrays'
)]
class NewsRepairHeadlinesCommand extends AbstractWriteCommand
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'dry-run', null, InputOption::VALUE_NONE,
            'Only report what would be changed, without writing to the database'
        );
    }

    protected function doExecute(array $fields): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');

        try {
            $rows = $this->connection->fetchAllAssociative('SELECT id, headline FROM tl_news');
        } catch (\Throwable $e) {
            return $this->outputError('Could not read tl_news: ' . $e->getMessage());
        }

        $repaired = [];

        foreach ($rows as $row) {
            $current = $row['headline'] ?? null;
            if (!\is_string($current) || '' === $current) {
                continue;
            }

            $plain = $this->extractPlainHeadline($current);
            if (null === $plain || $plain === $current) {
                continue;
            }

            $repaired[] = [
                'id'   => (int) $row['id'],
                'from' => $current,
                'to'   => $plain,
            ];

            if (!$dryRun) {
                $this->connection->update('tl_news', ['headline' => $plain], ['id' => (int) $row['id']]);
            }
        }

        $this->outputSuccess([
            'dry_run'  => $dryRun,
            'scanned'  => \count($rows),
            'repaired' => \count($repaired),
            'records'  => $repaired,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Returns the plain title for a serialized {value, unit} headline, or null
     * when the value is not such a payload and must be left alone.
     */
    private function extractPlainHeadline(string $value): ?string
    {
        // Cheap guard so genuine titles never reach unserialize().
        if (!str_starts_with($value, 'a:')) {
            return null;
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);
        if (!\is_array($decoded) || !\array_key_exists('value', $decoded)) {
            return null;
        }

        $plain = $decoded['value'];
        return \is_string($plain) ? $plain : null;
    }
}
