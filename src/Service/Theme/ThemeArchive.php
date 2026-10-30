<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Theme;

use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Contao\DC_Table;
use Contao\Theme;
use Contao\ZipReader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;

/**
 * The theme manager's export and import on the console (v1.9.0).
 *
 * Both run Contao's own code, `Contao\Theme`, rather than a rebuild: the import maps every
 * id anew (modules in layouts, image sizes, file paths to tl_files) and that mapping is
 * Contao's to keep right across versions. The class assumes the back end — a session for
 * its messages, a user for its permission check, a browser for the download — which
 * DcaCallbacks::inBackEnd() provides on the console; the download is caught, not sent.
 *
 * Example websites (`.sql`) are not imported here: Contao's form takes them together with
 * themes, but they empty tables. They have their own command (ExampleWebsite).
 */
class ThemeArchive
{
    /** Where uploaded archives and exports live, as in the back end. */
    public const TMP_DIR = 'system/tmp';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly DcaCallbacks $dcaCallbacks,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Contao's `.cto` of a theme, written to system/tmp.
     *
     * @return array{file: string, size: int, theme: string}
     */
    public function export(int $themeId): array
    {
        $this->framework->initialize();

        $name = $this->connection->fetchOne('SELECT name FROM tl_theme WHERE id = ?', [$themeId]);
        if (false === $name) {
            throw new \InvalidArgumentException(\sprintf('Theme %d not found.', $themeId));
        }

        // exportTheme() reads only the id of the data container it is given.
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $dc->id = $themeId;

        try {
            $this->dcaCallbacks->inBackEnd(static fn () => (new Theme())->exportTheme($dc));
        } catch (ResponseException $e) {
            $response = $e->getResponse();
            if ($response instanceof BinaryFileResponse) {
                // Contao writes a random name without extension and names the download;
                // here the file is the result, so it carries the extension itself.
                $source = $response->getFile()->getPathname();
                $target = $source . '.cto';
                if (!rename($source, $target)) {
                    throw new \RuntimeException(\sprintf('The export could not be renamed to %s.', basename($target)));
                }

                return ['file' => Path::makeRelative($target, $this->projectDir), 'size' => (int) filesize($target), 'theme' => (string) $name];
            }

            throw new \RuntimeException('Contao answered the export with something other than a file.', 0, $e);
        } catch (AccessDeniedException $e) {
            throw self::withoutConsoleUser($e);
        }

        throw new \RuntimeException('Contao finished the export without a file.');
    }

    /**
     * Contao's theme manager asks for the export/import right; on the console the user
     * asked is `contao-ai`, an admin. Refused means it is missing — the migration that
     * creates it has not run (Contao's own message points elsewhere).
     */
    private static function withoutConsoleUser(AccessDeniedException $e): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            $e->getMessage() . ' On the console the theme manager runs as the user "contao-ai", which contao:migrate creates; run it and try again.',
            0,
            $e,
        );
    }

    /**
     * What an import of $path would do, without doing it.
     *
     * @return array{themes: list<string>, tables: array<string, int>, overwrites: list<string>, new_files: int, missing_fields: array<string, list<string>>, example_websites: list<string>, existing_names: list<string>, lost_theme_elements: int, code_files: list<string>}
     */
    public function analyse(string $path): array
    {
        $this->framework->initialize();
        $absolute = $this->archive($path);

        $zip = new ZipReader(Path::makeRelative($absolute, $this->projectDir), true);
        $xml = null;
        $overwrites = [];
        $newFiles = 0;
        $examples = [];
        $code = [];

        while ($zip->next()) {
            $name = (string) $zip->file_name;

            if ('theme.xml' === $name) {
                $xml = new \DOMDocument();
                $xml->preserveWhiteSpace = false;
                $xml->loadXML($zip->unzip());
                continue;
            }

            // The paths Contao extracts, nothing else (Theme::extractThemeFiles()).
            if (!preg_match('#^(files|tl_files|templates|var/backups)/#', $name)) {
                continue;
            }
            if (preg_match('#^(templates|var/backups)/.+\.sql$#i', $name)) {
                $examples[] = $name;
            }

            $target = (string) preg_replace('#^tl_files/#', 'files/', $name);
            if (self::isCode($target)) {
                $code[] = $target;
            }
            if (is_file(Path::join($this->projectDir, $target))) {
                $overwrites[] = $target;
            } else {
                ++$newFiles;
            }
        }

        if (!$xml instanceof \DOMDocument) {
            throw new \InvalidArgumentException(\sprintf('%s holds no theme.xml; it is no theme archive.', basename($absolute)));
        }

        $themes = [];
        $tables = [];
        $missing = [];
        $lostElements = 0;
        foreach ($xml->getElementsByTagName('table') as $table) {
            $tableName = $table->getAttribute('name');
            $rows = $table->getElementsByTagName('row');
            $tables[$tableName] = $rows->length;
            $columns = $this->columns($tableName);

            foreach ($rows as $row) {
                foreach ($row->getElementsByTagName('field') as $field) {
                    $fieldName = $field->getAttribute('name');
                    if ('tl_theme' === $tableName && 'name' === $fieldName) {
                        $themes[] = $field->nodeValue ?? '';
                    }
                    // A .cto does not carry theme elements (5.6+), but layouts can place
                    // them (`content-<id>`): Contao's import finds no mapping and leaves the
                    // slot empty, with a PHP warning (measured on 5.7.13).
                    if ('tl_layout' === $tableName && 'modules' === $fieldName) {
                        $lostElements += substr_count((string) $field->nodeValue, '"content-');
                    }
                    // Contao's own warning before an import: a field the archive has and
                    // this database does not — its values are dropped.
                    if ([] !== $columns && !\in_array($fieldName, $columns, true)) {
                        $missing[$tableName][$fieldName] = $fieldName;
                    }
                }
            }
        }

        $existing = [] === $themes ? [] : array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT name FROM tl_theme WHERE name IN (?)', [$themes], [ArrayParameterType::STRING],
        ));

        return [
            'themes'           => $themes,
            'tables'           => $tables,
            'overwrites'       => $overwrites,
            'new_files'        => $newFiles,
            'missing_fields'   => array_map('array_values', $missing),
            'example_websites' => $examples,
            'existing_names'   => $existing,
            // Layout slots that will be empty after the import, see above.
            'lost_theme_elements' => $lostElements,
            'code_files'       => $code,
        ];
    }

    /**
     * Whether the server runs or evaluates $path once it is extracted (v1.14.0).
     *
     * Contao's extraction writes every file under files/, templates/ and var/backups/
     * without a type filter — a theme import is a code import, which is why it needs the
     * admin right. A legacy `.html5` template is PHP and replaces the core template of the
     * same name on the next request; Twig templates run their logic as well. The preview
     * named such files only when they overwrote one, a new one counted in `new_files`.
     */
    public static function isCode(string $path): bool
    {
        $name = strtolower(basename($path));

        return \in_array($name, ['.htaccess', '.user.ini'], true)
            || 1 === preg_match('/\.(php\d?|phtml|phar|phps|html5|twig)$/', $name);
    }

    /**
     * Import $path with Contao's own extraction.
     *
     * @return array{theme_ids: list<int>, messages: list<array{type: string, text: string}>}
     */
    public function import(string $path): array
    {
        $this->framework->initialize();
        $absolute = $this->archive($path);
        $relative = Path::makeRelative($absolute, $this->projectDir);

        $before = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM tl_theme');

        $db = Database::getInstance();
        $dbFields = [];
        foreach (['tl_files', 'tl_theme', 'tl_module', 'tl_layout', 'tl_image_size', 'tl_image_size_item'] as $table) {
            $dbFields[$table] = $db->getFieldNames($table);
        }

        try {
            $this->dcaCallbacks->inBackEnd(static function () use ($relative, $dbFields): void {
                // extractThemeFiles() is protected; Contao calls it from importTheme(), which
                // reads an upload form. Example websites inside the archive are skipped — it
                // imports one only when the form names it (`example_website`).
                (new class() extends Theme {
                    /**
                     * @param list<string>                 $files
                     * @param array<string, list<string>> $dbFields
                     */
                    public function extractOnly(array $files, array $dbFields): void
                    {
                        $this->extractThemeFiles($files, $dbFields);
                    }
                })->extractOnly([$relative], $dbFields);
            });
        } catch (RedirectResponseException) {
            // The back end's "back to the theme list" after a finished import.
        }

        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_theme WHERE id > ? ORDER BY id', [$before]));

        return ['theme_ids' => $ids, 'messages' => $this->dcaCallbacks->drainMessages()];
    }

    /** The archive as an absolute path, refused unless it is a .cto/.zip in system/tmp. */
    private function archive(string $path): string
    {
        $absolute = Path::canonicalize(Path::makeAbsolute($path, $this->projectDir));
        $tmp      = Path::join($this->projectDir, self::TMP_DIR);

        if (!Path::isBasePath($tmp, $absolute)) {
            throw new \InvalidArgumentException(\sprintf('The archive must lie in %s/ (contao-ai-cli uploads it there): %s', self::TMP_DIR, $path));
        }
        if (preg_match('/\.sql$/i', $absolute)) {
            throw new \InvalidArgumentException('An .sql file is an example website, not a theme: it empties tables. Use contao:example-website:import.');
        }
        if (!preg_match('/\.(cto|zip)$/i', $absolute)) {
            throw new \InvalidArgumentException(\sprintf('Not a theme archive (.cto or .zip): %s', basename($absolute)));
        }
        if (!is_file($absolute)) {
            throw new \InvalidArgumentException(\sprintf('No such file: %s', $path));
        }

        return $absolute;
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        if (!preg_match('/^tl_[a-z0-9_]+$/', $table)) {
            return [];
        }

        try {
            // getName(), not the keys: DBAL lower-cases those, Contao's fields are camelCase.
            return array_values(array_map(
                static fn ($column): string => $column->getName(),
                $this->connection->createSchemaManager()->listTableColumns($table),
            ));
        } catch (\Throwable) {
            return [];
        }
    }
}
