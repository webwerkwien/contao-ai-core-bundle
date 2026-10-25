<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * The DBAFS hash belongs to Contao, not to us.
 *
 * `Contao\File::getHash()` is `md5_file()` up to Contao 5.7 and from Contao 6.0 on
 * `hash_file('xxh128', …)`. Whoever computes the hash themselves writes a wrong
 * value to `tl_files` on one of the two versions. That is exactly what happened up to
 * v0.6.1: on overwrite `FileWriteCommand` set
 * `$filesModel->hash = md5($content)` — off every time on Contao 6.0, until
 * the next `contao:filesync` corrected it.
 *
 * ⚠️ **What this test can do and what it cannot.** It reads source code and checks that
 * nobody computes the hash themselves again. It does **not** prove that the
 * written value is right — that takes a real Contao installation,
 * and there is none here. The proof came from the live test
 * against 5.7.13 and 6.0.0. This test only makes sure it is not reverted
 * unnoticed.
 */
class DbafsHashIsNotComputedLocallyTest extends TestCase
{
    /**
     * Every assignment to a `hash` property in all of `src/`.
     *
     * Deliberately via a search instead of a maintained file list: a new
     * command that sets a hash is covered from its first line.
     *
     * @return array<string, string> file:line => source line
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
                // Comment lines do not count — that is where the reasoning lives.
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
        // Without this assertion the test below would be silently green as soon as
        // the notation changes — a check that no longer checks anything.
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

    /**
     * Whoever sets a file's hash must also update the folders above it.
     *
     * A folder hash is built from those of its children. Up to v0.26.0
     * `FileWriteCommand` set only the file hash on overwrite; every folder above it
     * kept the old one until `contao:filesync` ran — measured on
     * 5.3.51, 5.7.13 and 6.0.0 in the regression run before 1.0. Same limit as above:
     * this checks the source, the proof came from the live run (`file sync` reports
     * "No changes" afterwards).
     */
    public function testEveryFileThatSetsAHashAlsoUpdatesTheFolderHashes(): void
    {
        $src = \dirname(__DIR__, 2) . '/src/Command/';

        foreach (array_unique(array_map(static fn (string $k): string => explode(':', $k)[0], array_keys($this->hashAssignments()))) as $file) {
            $this->assertStringContainsString(
                'Dbafs::updateFolderHashes(',
                (string) file_get_contents($src . $file),
                $file . ' setzt einen Datei-Hash, zieht die Ordner-Hashes aber nicht nach.',
            );
        }
    }
}
