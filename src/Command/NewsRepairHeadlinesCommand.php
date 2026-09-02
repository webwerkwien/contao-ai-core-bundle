<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
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
    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
    ) {
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

        // 🔴 Aufgefallen erst auf c5, nicht in 603 grünen Tests: seit der Fix
        // für H-3 über `writer()->update()` läuft, braucht dieser Befehl das
        // Model-Register. Vorher schrieb er rohes SQL und kam ohne aus.
        // Ohne diese Zeile: `There is no class for table "tl_news" registered
        // in $GLOBALS['TL_MODELS']` — und zwar **nur** im echten Lauf, weil
        // `--dry-run` gar nicht schreibt und deshalb sauber durchging.
        $this->framework->initialize();

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

            $id = (int) $row['id'];

            $repaired[] = [
                'id'   => $id,
                'from' => $current,
                'to'   => $plain,
            ];

            if (!$dryRun) {
                // 🔴 H-3 (Audit 2026-09-02): hier stand ein rohes
                // `$this->connection->update('tl_news', …)`. Ohne Version.
                //
                // `--dry-run` zeigt vorher, was passieren würde — aber eine
                // ausgeführte Fehlreparatur war endgültig. Und der Erkenner ist
                // eine Heuristik: `extractPlainHeadline()` hält alles für
                // reparierbar, was mit `a:` beginnt und sich entserialisieren
                // lässt. Ein Titel, der zufällig so aussieht, wurde
                // stillschweigend ersetzt.
                //
                // 🎯 Eine Reparatur ist genau der Vorgang, bei dem man sich am
                // ehesten irrt — sie läuft über Bestandsdaten, die niemand mehr
                // im Kopf hat. Über den Writer entsteht je Datensatz ein
                // tl_version-Snapshot, und `version:restore` holt einen
                // Fehlgriff einzeln zurück.
                $this->writer()->update(
                    'tl_news',
                    $id,
                    ['headline' => $plain],
                    $this->resolveOperator(),
                );
            }
        }

        $this->outputSuccess([
            'dry_run'  => $dryRun,
            'scanned'  => \count($rows),
            'repaired' => \count($repaired),
            // H-3: sagt dem Aufrufer, dass ein Rückweg existiert — und im
            // Trockenlauf ausdrücklich, dass noch nichts entstanden ist.
            'versioned' => !$dryRun,
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
