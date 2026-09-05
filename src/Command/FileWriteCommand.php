<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * 🔴 H-4 (Audit 2026-09-02): Dieser Befehl überschreibt Dateiinhalte, ohne die
 * vorherigen Bytes zu sichern — und deklarierte das nirgends.
 *
 * ⚠️ Nachgesehen, wie Contao es macht: `DC_Folder::source()` versioniert um den
 * Schreibvorgang herum (`Versions::initialize()` … `create()`), aber
 * `Versions::create()` ist ein `SELECT * FROM <table> WHERE id=?` — es sichert
 * die **Datenbankzeile**, nicht den Dateiinhalt. Contaos eigener Datei-Editor
 * überschreibt also genauso unwiederbringlich; der gespeicherte `hash` lässt
 * eine Änderung erkennen, nicht zurücknehmen.
 *
 * 🎯 Deshalb kein eigenes Backup-Regime, das von Contao abwiche und Fragen nach
 * Ablageort und Aufbewahrung aufwürfe — sondern die Deklaration. `irreversible`
 * existiert in `AiContract` genau dafür, und ein aufrufender Agent liest sie,
 * bevor er zugreift. **Eine unumkehrbare Wirkung, die niemand ankündigt, ist
 * der eigentliche Mangel.**
 */
#[AiContract(
    writes: true,
    tables: ['tl_files'],
    trace: ['tl_version'],
    traceWhen: 'before',
    irreversible: 'overwrites the file content on disk — the tl_version snapshot holds the tl_files row and its hash, not the previous bytes',
    repeatable: true,
    answerShape: ['status', 'path'],
)]
#[AsCommand(name: 'contao:file:write', description: 'Write a text file to files/ and create a tl_version snapshot')]
class FileWriteCommand extends AbstractWriteCommand
{
    private const MAX_SOURCE_BYTES = 10485760; // 10 MB

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    // File operations belong under the FILES action, the same bucket the back
    // end's own file manager writes to.
    protected function systemLogAction(): string
    {
        return ContaoContext::FILES;
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('path',   null, InputOption::VALUE_REQUIRED, 'Destination path relative to Contao root, e.g. files/scripts/style.css')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Absolute path of temp file on the server to read content from');
    }

    protected function doExecute(array $fields): int
    {
        $path   = $this->input->getOption('path');
        $source = $this->input->getOption('source');

        if (!$path || !$source) {
            return $this->outputError('--path and --source are required');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must start with files/ and must not contain ".."');
        }

        $realSource = realpath($source);
        $uploadDir  = rtrim($this->projectDir, '/') . '/var/bridge-uploads/';
        $realUpload = realpath($uploadDir);
        if ($realUpload === false) {
            return $this->outputError('Upload directory var/bridge-uploads/ does not exist on this server');
        }
        $realUpload = rtrim($realUpload, '/') . '/';

        if ($realSource === false || !str_starts_with($realSource, $realUpload)) {
            return $this->outputError('--source must be under var/bridge-uploads/');
        }

        if (!is_file($realSource)) {
            return $this->outputError("Source file not found");
        }

        if (filesize($realSource) > self::MAX_SOURCE_BYTES) {
            return $this->outputError('Source file exceeds maximum allowed size of 10 MB');
        }

        $content = file_get_contents($realSource);
        if ($content === false) {
            return $this->outputError('Cannot read source file');
        }

        $this->framework->initialize();

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;

        // Realpath jail: walk up to deepest existing ancestor to catch symlinks
        $jailRoot   = realpath($this->projectDir) . DIRECTORY_SEPARATOR;
        $jailCheck  = $absPath;
        while (!file_exists($jailCheck) && $jailCheck !== dirname($jailCheck)) {
            $jailCheck = dirname($jailCheck);
        }
        $realAncestor = realpath($jailCheck);
        if ($realAncestor === false || !str_starts_with($realAncestor . DIRECTORY_SEPARATOR, $jailRoot)) {
            return $this->outputError('Access denied: path resolves outside allowed directory');
        }

        // Create parent directories if needed
        $dir = dirname($absPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return $this->outputError("Cannot create directory for: {$path}");
        }

        $filesModel = FilesModel::findByPath($path);
        if ($filesModel !== null) {
            // Snapshot the record before overwrite — Contao convention: version = pre-change state
            $this->versionManager->createVersion('tl_files', (int) $filesModel->id, $this->resolveOperator());
        }

        if (file_put_contents($absPath, $content) === false) {
            return $this->outputError('Cannot write file');
        }

        $bytes = strlen($content);

        if ($filesModel !== null) {
            // ⚠️ Den Hash nicht selbst rechnen. Bis v0.6.1 stand hier
            // `md5($content)` — das stimmt nur bis Contao 5.7.
            // `Contao\File::getHash()` ist dort `md5_file()`, ab Contao 6.0
            // aber `hash_file('xxh128', …)`. Auf Contao 6 landete nach jedem
            // Überschreiben ein falscher Hash in tl_files, bis der nächste
            // `contao:filesync` ihn korrigierte. Gemessen am 2026-09-05 gegen
            // 5.7.13 und 6.0.0.
            //
            // Contaos eigener Datei-Editor macht es genauso (`DC_Folder::source()`:
            // `$objMeta->hash = $objFile->hash; $objMeta->save();`) — die
            // Version entscheidet über den Algorithmus, nicht wir.
            $filesModel->tstamp = time();
            $filesModel->hash   = (new File($path))->hash;
            $filesModel->save();
            $version = true;
        } else {
            // New file: register it in the DBAFS so it receives a tl_files
            // record (and thus a UUID). Without this the file exists on disk
            // but stays invisible to Contao until the next contao:filesync —
            // which is exactly what blocks referencing it in a content element.
            $version = false;
            try {
                $filesModel = Dbafs::addResource($path);
            } catch (\Throwable $e) {
                $this->logger->warning('contao:file:write DBAFS sync failed', [
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]);
                $filesModel = null;
            }
        }

        // Expose the file UUID so callers can reference the file (e.g. set
        // tl_content.singleSRC) without a second lookup. Null only if the
        // DBAFS sync above failed.
        $uuid = ($filesModel !== null && $filesModel->uuid !== null)
            ? StringUtil::binToUuid($filesModel->uuid)
            : null;

        $this->outputSuccess([
            'path'    => $path,
            'bytes'   => $bytes,
            'version' => $version,
            'uuid'    => $uuid,
        ]);

        return Command::SUCCESS;
    }
}
