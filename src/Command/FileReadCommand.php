<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:file:read', description: 'Read a text file from files/ and return its content as JSON')]
class FileReadCommand extends AbstractReadCommand
{
    private const MAX_BYTES = 524288; // 512 KB

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'File path relative to Contao root, e.g. files/scripts/style.css');
    }

    protected function doExecute(): int
    {
        // 🔴 Aufgefallen 2026-09-05 durch PHPStan: `$framework` war injiziert
        // und wurde nie gelesen. Der Befehl liest weiter unten `FilesModel`,
        // und ein Model braucht `$GLOBALS['TL_MODELS']` — gefüllt erst hier.
        //
        // 🎯 Das ist derselbe Bau wie bei `news:repair-headlines`, das mit 603
        // grünen Tests und sauberem Dry-Run starb: Die Schwester-Basisklasse
        // `AbstractModelReadCommand` ruft `initialize()`, dieser Befehl erbt
        // aber von `AbstractReadCommand` und tat es nicht. Ob es im Betrieb
        // knallte, hing allein daran, ob die Konsole das Framework schon
        // gebootet hatte — eine Abhängigkeit, auf die sich niemand verlassen
        // wollte, als sie bekannt war.
        //
        // `initialize()` ist idempotent; der Aufruf kostet nichts und macht die
        // vorhandene Injektion zu dem, wofür sie gedacht war.
        $this->framework->initialize();

        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || !str_starts_with($path, 'files/')) {
            return $this->outputError('Path must start with files/ and must not contain ".."');
        }

        $absPath = rtrim($this->projectDir, '/') . '/' . $path;
        if (!is_file($absPath)) {
            return $this->outputError("File not found: {$path}");
        }

        // Realpath jail: reject symlinks pointing outside projectDir
        $real = realpath($absPath);
        if ($real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, realpath($this->projectDir) . DIRECTORY_SEPARATOR)) {
            return $this->outputError('Access denied: path resolves outside allowed directory');
        }
        $absPath = $real;

        $size = filesize($absPath);
        if ($size > self::MAX_BYTES) {
            return $this->outputError("File too large ({$size} bytes). Maximum is " . self::MAX_BYTES . " bytes.");
        }

        $content = file_get_contents($absPath);
        if ($content === false) {
            return $this->outputError("Could not read file: {$path}");
        }

        // Reject binary files
        if (!mb_check_encoding($content, 'UTF-8')) {
            return $this->outputError("File is not valid UTF-8 text: {$path}");
        }

        $this->outputRecord(['path' => $path, 'content' => $content, 'size' => $size]);
        return Command::SUCCESS;
    }
}
