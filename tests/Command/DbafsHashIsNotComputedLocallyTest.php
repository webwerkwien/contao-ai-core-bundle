<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * Der DBAFS-Hash gehört Contao, nicht uns.
 *
 * `Contao\File::getHash()` ist bis Contao 5.7 `md5_file()` und ab Contao 6.0
 * `hash_file('xxh128', …)`. Wer den Hash selbst rechnet, schreibt auf einer der
 * beiden Versionen einen falschen Wert nach `tl_files`. Genau das ist bis
 * v0.6.1 passiert: `FileWriteCommand` setzte beim Überschreiben
 * `$filesModel->hash = md5($content)` — auf Contao 6.0 jedes Mal daneben, bis
 * der nächste `contao:filesync` es korrigierte.
 *
 * ⚠️ **Was dieser Test kann und was nicht.** Er liest Quelltext und prüft, dass
 * niemand den Hash wieder selbst ausrechnet. Er belegt **nicht**, dass der
 * geschriebene Wert stimmt — dafür braucht es eine echte Contao-Installation,
 * und die gibt es hier nicht. Der Beweis kam am 2026-09-05 aus dem Live-Test
 * gegen 5.7.13 und 6.0.0. Dieser Test hält nur fest, dass es nicht unbemerkt
 * zurückgedreht wird.
 */
class DbafsHashIsNotComputedLocallyTest extends TestCase
{
    /**
     * Jede Zuweisung an eine `hash`-Eigenschaft im gesamten `src/`.
     *
     * Bewusst über eine Suche statt über eine gepflegte Dateiliste: ein neuer
     * Befehl, der einen Hash setzt, ist damit ab der ersten Zeile abgedeckt.
     *
     * @return array<string, string> Datei:Zeile => Quelltextzeile
     */
    private function hashAssignments(): array
    {
        $src   = \dirname(__DIR__, 2) . '/src';
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $no => $line) {
                // Kommentarzeilen zählen nicht — dort steht die Begründung.
                if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line)) {
                    continue;
                }

                if (preg_match('/->hash\s*=[^=]/', $line)) {
                    $key         = basename($file->getPathname()) . ':' . ($no + 1);
                    $found[$key] = trim($line);
                }
            }
        }

        return $found;
    }

    public function testTheSearchActuallyFindsSomething(): void
    {
        // Ohne diese Zusicherung wäre der Test unten still grün, sobald sich
        // die Schreibweise ändert — eine Prüfung, die nichts mehr prüft.
        $this->assertNotEmpty(
            $this->hashAssignments(),
            'Keine einzige Hash-Zuweisung gefunden. Entweder hat sich die '
            . 'Schreibweise geändert, dann gehört die Suche angepasst — oder der '
            . 'Test prüft ab jetzt nichts mehr.',
        );
    }

    public function testHashIsNeverComputedFromAHardCodedAlgorithm(): void
    {
        foreach ($this->hashAssignments() as $where => $line) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(md5|sha1|crc32c?|hash|md5_file|sha1_file|hash_file)\s*\(/',
                $line,
                \sprintf(
                    "%s rechnet den DBAFS-Hash selbst aus:\n    %s\n"
                    . 'Der Algorithmus hängt an der Contao-Version (5.7: md5, 6.0: xxh128). '
                    . 'Stattdessen `(new File($path))->hash` oder `Dbafs::addResource($path)` — '
                    . 'so macht es Contaos eigener Datei-Editor in DC_Folder::source().',
                    $where,
                    $line,
                ),
            );
        }
    }
}
