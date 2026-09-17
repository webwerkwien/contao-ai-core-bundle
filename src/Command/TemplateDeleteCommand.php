<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\Template\TemplateCacheRefresher;

/**
 * Delete a Twig template below templates/ — as Contao's Template Studio does.
 *
 * Until v0.20.0 there was no way to delete a template through the bundle (Nr. 51 of the
 * ConpAI 1.0 acceptance test, 2026-09-17). See TemplateDeleteCommandTest.
 *
 * Mirrors the Template Studio of 5.7 (`DeleteOperation`, `AbstractDeleteVariantOperation`):
 * the file, then compiled templates and hierarchy, then — for a variant of a content
 * element or front-end module — `customTpl` of every record using it back to `''`, so it
 * renders the default template. Unlike the Studio, each changed record gets a version and
 * a new `tstamp`. Other templates that extend or include a deleted one are not checked —
 * the Studio does not check them either.
 * Contao 5.3 has no Template Studio; the rules are rebuilt, not called.
 */
#[AsCommand(name: 'contao:template:delete', description: 'Delete a Twig template below templates/ — records using a deleted variant fall back to the default template')]
class TemplateDeleteCommand extends AbstractWriteCommand
{
    /** Variants whose usages Contao's Template Studio resets: prefix => table (field customTpl). */
    private const VARIANT_TABLES = ['content_element' => 'tl_content', 'frontend_module' => 'tl_module'];

    private ?TemplateCacheRefresher $templateCacheRefresher = null;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[Required]
    public function setTemplateCacheRefresher(TemplateCacheRefresher $templateCacheRefresher): void
    {
        $this->templateCacheRefresher = $templateCacheRefresher;
    }

    protected function systemLogAction(): string
    {
        return ContaoContext::FILES;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Template path relative to Contao root, e.g. templates/content_element/text/hero.html.twig');
    }

    /**
     * The path as written below templates/: `/` only, no empty or `.` segments, a
     * `.html.twig` file. Null for `..`, anything outside templates/ and other file types.
     */
    public static function canonicalPath(string $path): ?string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment || str_contains($segment, "\0")) {
                return null;
            }
            $segments[] = $segment;
        }

        if (\count($segments) < 2 || 'templates' !== $segments[0] || !str_ends_with((string) end($segments), '.html.twig')) {
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * The variant identifier and the table whose `customTpl` may hold it — only for what
     * Contao's Template Studio migrates: `templates/<prefix>/<type>/<name>.html.twig`, where
     * the name may have folders of its own (`canExecute()`: `<prefix>/[^/]+/.+`).
     *
     * @return array{identifier: string, table: string}|null
     */
    public static function variantReference(string $path): ?array
    {
        if (1 !== preg_match('~^templates/(content_element|frontend_module)/[^/]+/.+\.html\.twig$~', $path, $m)) {
            return null;
        }

        return [
            'identifier' => substr($path, \strlen('templates/'), -\strlen('.html.twig')),
            'table'      => self::VARIANT_TABLES[$m[1]],
        ];
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        $path = self::canonicalPath((string) $path);
        if (null === $path) {
            return $this->outputError('Path must be a .html.twig file below templates/ and must not contain "..".');
        }

        $absolute = $this->projectDir . '/' . $path;

        if (is_link($absolute)) {
            return $this->outputError("{$path} is a symbolic link. Nothing was deleted.");
        }
        if (!is_file($absolute)) {
            return $this->outputError("Template not found: {$path}. Nothing was deleted.");
        }

        // No linked folder on the way (lesson of file delete, v0.19.0).
        $root     = realpath($this->projectDir . '/templates');
        $parent   = realpath(\dirname($absolute));
        $expected = false === $root ? null : str_replace('\\', '/', $root) . substr(\dirname($path), \strlen('templates'));

        if (false === $parent || null === $expected || str_replace('\\', '/', $parent) !== $expected) {
            return $this->outputError("{$path} runs through a symbolic link or leaves templates/. Nothing was deleted.");
        }

        $this->framework->initialize();

        $reference = self::variantReference($path);
        $ids       = null === $reference ? [] : array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM ' . $this->connection->quoteIdentifier($reference['table']) . ' WHERE customTpl = ?',
            [$reference['identifier']],
        ));

        if (!unlink($absolute)) {
            return $this->outputError("{$path} could not be deleted. Nothing was changed.");
        }

        $data = ['path' => $path, 'deleted' => true, 'undoable' => false];

        // The hierarchy first, right after the file, as the Studio does: a record still
        // naming the variant then falls back to the default instead of a missing file.
        if (null !== $this->templateCacheRefresher) {
            try {
                $notRefreshed                   = $this->templateCacheRefresher->refresh();
                $data['templateCacheRefreshed'] = [] === $notRefreshed;

                if ([] !== $notRefreshed) {
                    $data['cacheWarning'] = implode(' ', $notRefreshed);
                }
            } catch (\Throwable $e) {
                $data['templateCacheRefreshed'] = false;
                $data['cacheWarning']           = 'The template was deleted, but Contao\'s template cache could not be refreshed (' . $e->getMessage() . '). Run cache clear.';
            }
        }

        // As AbstractDeleteVariantOperation::migrateDatabaseUsages(), plus a version each.
        // The file is gone by now: a failure here is logged and names what is left.
        $migrated = [];

        try {
            foreach ($ids as $id) {
                \assert(null !== $reference);
                $this->connection->update($reference['table'], ['customTpl' => '', 'tstamp' => time()], ['id' => $id]);
                $this->createVersion($reference['table'], $id);
                $migrated[] = $id;
            }
        } catch (\Throwable $e) {
            \assert(null !== $reference);
            $notReset = array_values(array_diff($ids, $migrated));
            $message  = \sprintf(
                '%s was deleted, but customTpl of %s %s could not be reset or versioned (%s). Set customTpl to \'\' on them.',
                $path,
                $reference['table'],
                implode(', ', $notReset),
                $e->getMessage(),
            );

            $this->logSuccess($data + [
                'migratedUsages' => ['table' => $reference['table'], 'field' => 'customTpl', 'ids' => $migrated],
                'notReset'       => $notReset,
                'error'          => $message,
            ]);

            return $this->outputError($message);
        }

        if ([] !== $ids) {
            \assert(null !== $reference);
            $data['migratedUsages'] = ['table' => $reference['table'], 'field' => 'customTpl', 'ids' => $ids];
        }

        $this->outputSuccess($data);

        return Command::SUCCESS;
    }
}
