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
 * Rename a Twig template variant below templates/ — as Contao's Template Studio does.
 *
 * Templates are referenced by name, not by UUID: writing the variant anew and deleting the
 * old one would reset every element using it to the default template. A rename carries
 * them along instead.
 *
 * Mirrors the Template Studio of 5.7 and 6.0 (`AbstractRenameVariantOperation`): only a
 * variant is renamed — `templates/<prefix>/<type>/<name>.html.twig` for a content element,
 * a front-end module or a page — and only its last segment; an override must keep its
 * name to override anything. The file is moved, the compiled templates and the hierarchy
 * are refreshed, then every record naming the variant gets the new name (`tl_content` and
 * `tl_module` in `customTpl`, `tl_layout` in `template`). Unlike the Studio, each changed
 * record gets a version and a new `tstamp`, as in TemplateDeleteCommand. Other templates
 * that extend or include the variant are not rewritten — the Studio does not do that
 * either. Contao 5.3 has no Template Studio; the rules are rebuilt, not called.
 */
#[AsCommand(name: 'contao:template:rename', description: 'Rename a Twig template variant below templates/ — records using it follow the new name')]
class TemplateRenameCommand extends AbstractWriteCommand
{
    /** Variants whose usages Contao's Template Studio migrates: prefix => [table, field]. */
    private const VARIANT_USAGES = [
        'content_element' => ['tl_content', 'customTpl'],
        'frontend_module' => ['tl_module', 'customTpl'],
        'page'            => ['tl_layout', 'template'],
    ];

    private const SUFFIX = '.html.twig';

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
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Variant path relative to Contao root, e.g. templates/content_element/text/hero.html.twig')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The new variant name — letters, digits, _ and -, e.g. teaser');
    }

    /**
     * The variant identifier and where records name it — only for what the Template
     * Studio renames: `templates/<prefix>/<type>/<name>.html.twig`, the name possibly with
     * folders of its own (`canExecute()`: `<prefix>/[^/]+/.+`). Null for anything else.
     *
     * @return array{identifier: string, table: string, field: string}|null
     */
    public static function variantReference(string $path): ?array
    {
        if (1 !== preg_match('~^templates/(content_element|frontend_module|page)/[^/]+/.+\.html\.twig$~', $path, $m)) {
            return null;
        }

        [$table, $field] = self::VARIANT_USAGES[$m[1]];

        return [
            'identifier' => substr($path, \strlen('templates/'), -\strlen(self::SUFFIX)),
            'table'      => $table,
            'field'      => $field,
        ];
    }

    /**
     * The variant name $name stands for, `.html.twig` taken off, or null when it is none:
     * letters, digits, `_` and `-`, no folder — the variant stays where it is.
     */
    public static function variantName(string $name): ?string
    {
        if (str_ends_with($name, self::SUFFIX)) {
            $name = substr($name, 0, -\strlen(self::SUFFIX));
        }

        return 1 === preg_match('/^[A-Za-z0-9_-]{1,200}$/', $name) ? $name : null;
    }

    protected function doExecute(array $fields): int
    {
        $path = (string) $this->input->getOption('path');
        $name = (string) $this->input->getOption('name');

        if ('' === $path) {
            return $this->outputError('--path is required');
        }
        if ('' === $name) {
            return $this->outputError('--name is required: the new variant name, e.g. teaser.');
        }

        $path = TemplateDeleteCommand::canonicalPath($path);
        if (null === $path) {
            return $this->outputError('Path must be a .html.twig file below templates/ and must not contain "..". Nothing was renamed.');
        }

        $reference = self::variantReference($path);
        if (null === $reference) {
            return $this->outputError("{$path} is no variant of a content element, front-end module or page. Only those are renamed, as in the Template Studio: an override must keep its name to override, and other templates are named by those that include them. Nothing was renamed.");
        }

        $newName = self::variantName($name);
        if (null === $newName) {
            return $this->outputError('--name must be a variant name: letters, digits, _ and -, no folder. Nothing was renamed.');
        }

        $target = \dirname($path) . '/' . $newName . self::SUFFIX;

        if ($target === $path) {
            return $this->outputError("{$path} already has this name. Nothing was renamed.");
        }

        $absolute = $this->projectDir . '/' . $path;

        if (is_link($absolute)) {
            return $this->outputError("{$path} is a symbolic link. Nothing was renamed.");
        }
        if (!is_file($absolute)) {
            return $this->outputError("Template not found: {$path}. Nothing was renamed.");
        }
        if (is_link($this->projectDir . '/' . $target) || file_exists($this->projectDir . '/' . $target)) {
            return $this->outputError("{$target} already exists. Nothing was renamed — the Template Studio does not overwrite either.");
        }

        // No linked folder on the way (lesson of file delete, v0.19.0).
        $root     = realpath($this->projectDir . '/templates');
        $parent   = realpath(\dirname($absolute));
        $expected = false === $root ? null : str_replace('\\', '/', $root) . substr(\dirname($path), \strlen('templates'));

        if (false === $parent || null === $expected || str_replace('\\', '/', $parent) !== $expected) {
            return $this->outputError("{$path} runs through a symbolic link or leaves templates/. Nothing was renamed.");
        }

        $this->framework->initialize();

        $newIdentifier = substr($target, \strlen('templates/'), -\strlen(self::SUFFIX));
        $ids           = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM ' . $this->connection->quoteIdentifier($reference['table'])
            . ' WHERE ' . $this->connection->quoteIdentifier($reference['field']) . ' = ?',
            [$reference['identifier']],
        ));

        if (!rename($absolute, $this->projectDir . '/' . $target)) {
            return $this->outputError("{$path} could not be renamed. Nothing was changed.");
        }

        $data = [
            'path'          => $path,
            'to'            => $target,
            'identifier'    => $reference['identifier'],
            'newIdentifier' => $newIdentifier,
        ];

        // The hierarchy first, right after the file, as the Studio does.
        if (null !== $this->templateCacheRefresher) {
            try {
                $notRefreshed                   = $this->templateCacheRefresher->refresh();
                $data['templateCacheRefreshed'] = [] === $notRefreshed;

                if ([] !== $notRefreshed) {
                    $data['cacheWarning'] = implode(' ', $notRefreshed);
                }
            } catch (\Throwable $e) {
                $data['templateCacheRefreshed'] = false;
                $data['cacheWarning']           = 'The template was renamed, but Contao\'s template cache could not be refreshed (' . $e->getMessage() . '). Run cache clear.';
            }
        }

        // As AbstractRenameVariantOperation::migrateDatabaseUsages(), plus a version each.
        // The file has its new name by now: a failure here is logged and names what is left.
        $migrated = [];

        try {
            foreach ($ids as $id) {
                $this->initializeVersion($reference['table'], $id);
                $this->connection->update($reference['table'], [$reference['field'] => $newIdentifier, 'tstamp' => time()], ['id' => $id]);
                $this->createVersion($reference['table'], $id);
                $migrated[] = $id;
            }
        } catch (\Throwable $e) {
            $left    = array_values(array_diff($ids, $migrated));
            $message = \sprintf(
                '%s was renamed to %s, but %s of %s %s could not be updated or versioned (%s). Set %s to \'%s\' on them.',
                $path,
                $target,
                $reference['field'],
                $reference['table'],
                implode(', ', $left),
                $e->getMessage(),
                $reference['field'],
                $newIdentifier,
            );

            $this->logSuccess($data + [
                'migratedUsages' => ['table' => $reference['table'], 'field' => $reference['field'], 'ids' => $migrated],
                'notMigrated'    => $left,
                'error'          => $message,
            ]);

            return $this->outputError($message);
        }

        if ([] !== $ids) {
            $data['migratedUsages'] = ['table' => $reference['table'], 'field' => $reference['field'], 'ids' => $ids];
        }

        $this->outputSuccess($data);

        return Command::SUCCESS;
    }
}
