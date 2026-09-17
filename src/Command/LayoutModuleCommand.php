<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Put a module into a layout column, or take it out (v0.16.0).
 *
 * Until then the `moduleWizard` value had to be written by hand; since v0.16.0 it can
 * be JSON. This command checks what JSON cannot: the module exists and belongs to the
 * layout's theme (0 is the articles), and the column exists — derived for a classic
 * `fe_page` layout exactly as Contao's ModuleWizard does. See LayoutModuleCommandTest.
 */
#[AsCommand(name: 'contao:layout:module', description: 'Add a module to a layout column, or remove it with --remove')]
class LayoutModuleCommand extends AbstractWriteCommand
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('layout', null, InputOption::VALUE_REQUIRED, 'Layout ID (tl_layout)')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module ID (tl_module), 0 for the articles, or content-<id> for a content element of the theme')
            ->addOption('col', null, InputOption::VALUE_REQUIRED, 'Column: main, header, left, right, footer or a custom section id')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the module (from --col, or from every column without it)');
    }

    protected function doExecute(array $fields): int
    {
        $layoutId = (int) $this->input->getOption('layout');
        $moduleId = $this->input->getOption('module');
        $col      = $this->input->getOption('col');
        $remove   = (bool) $this->input->getOption('remove');

        $elementId = self::themeElementId((string) $moduleId);

        if ($layoutId < 1 || null === $moduleId || '' === $moduleId || (!ctype_digit((string) $moduleId) && null === $elementId)) {
            return $this->outputError('--layout and --module (a number, 0 for the articles, or content-<id> for a content element of the theme) are required');
        }
        if (!$remove && (null === $col || '' === $col)) {
            return $this->outputError('--col is required when adding a module');
        }

        $this->framework->initialize();

        // Backticks: `rows` is a reserved word since MySQL 8 (window functions). The
        // unquoted query failed on c5 with a syntax error (2026-09-16).
        $layout = $this->connection->fetchAssociative('SELECT `id`, `pid`, `template`, `rows`, `cols`, `sections`, `modules` FROM `tl_layout` WHERE `id` = ?', [$layoutId]);
        if (false === $layout) {
            return $this->outputError("Layout not found: {$layoutId}");
        }

        if (null !== $elementId) {
            $moduleId = 'content-' . $elementId;

            if (!$remove) {
                $element = $this->connection->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ?', [$elementId]);
                if (false === $element || 'tl_theme' !== $element['ptable']) {
                    return $this->outputError("Content element {$elementId} is not a content element of a theme. Layouts take the theme's own elements (Contao 5.7+), see Themes → Content elements.");
                }
                if ((int) $element['pid'] !== (int) $layout['pid']) {
                    return $this->outputError("Content element {$elementId} belongs to theme {$element['pid']}, layout {$layoutId} to theme {$layout['pid']}. Contao offers only the elements of the layout's own theme.");
                }
            }
        } else {
            $moduleId = (int) $moduleId;
        }

        if (!$remove && \is_int($moduleId) && 0 !== $moduleId) {
            $themeId = $this->connection->fetchOne('SELECT pid FROM tl_module WHERE id = ?', [$moduleId]);
            if (false === $themeId) {
                return $this->outputError("Module not found: {$moduleId}");
            }
            if ((int) $themeId !== (int) $layout['pid']) {
                return $this->outputError("Module {$moduleId} belongs to theme {$themeId}, layout {$layoutId} to theme {$layout['pid']}. Contao offers only the modules of the layout's own theme.");
            }
        }

        // A classic layout's columns are known; a Twig layout's slots live in its template.
        $isLegacy     = 'fe_page' === ($layout['template'] ?? '') || str_starts_with((string) ($layout['template'] ?? ''), 'fe_');
        $columns      = $isLegacy ? self::legacyColumns($layout) : null;
        $columnCheck  = null !== $columns;

        if (!$remove && null !== $columns && !\in_array($col, $columns, true)) {
            return $this->outputError(\sprintf(
                'Layout %d has no column "%s". Nothing was written. Its columns: %s',
                $layoutId,
                $col,
                implode(', ', $columns),
            ));
        }

        $modules = StringUtil::deserialize($layout['modules'] ?? null, true);
        [$after, $changed] = self::applyChange(array_values($modules), $moduleId, $col ?: null, $remove);

        if ($changed) {
            $this->writer()->update('tl_layout', $layoutId, ['modules' => serialize($after)], $this->resolveOperator());
        }

        $this->outputSuccess([
            'layout'         => $layoutId,
            'changed'        => $changed,
            'modules'        => $after,
            'column_checked' => $columnCheck,
        ]);

        return Command::SUCCESS;
    }

    /**
     * The columns of a classic layout, as Contao's ModuleWizard derives them.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public static function legacyColumns(array $row): array
    {
        $rows = (string) ($row['rows'] ?? '');
        $cols = (string) ($row['cols'] ?? '');

        $columns = ['main'];

        if (\in_array($rows, ['2rwh', '3rw'], true)) {
            $columns[] = 'header';
        }
        if (\in_array($cols, ['2cll', '3cl'], true)) {
            $columns[] = 'left';
        }
        if (\in_array($cols, ['2clr', '3cl'], true)) {
            $columns[] = 'right';
        }
        if (\in_array($rows, ['2rwf', '3rw'], true)) {
            $columns[] = 'footer';
        }

        $sections = @unserialize((string) ($row['sections'] ?? ''), ['allowed_classes' => false]);
        if (\is_array($sections)) {
            foreach ($sections as $section) {
                if (\is_array($section) && !empty($section['id'])) {
                    $columns[] = (string) $section['id'];
                }
            }
        }

        return $columns;
    }

    /**
     * The id of `content-<id>`, the form a layout stores a theme's content element in
     * since Contao 5.7 (ModuleWizard, PageRegular). Null for anything else (Nr. 68).
     */
    public static function themeElementId(string $module): ?int
    {
        return preg_match('/^content-([1-9]\d*)$/', $module, $m) ? (int) $m[1] : null;
    }

    /**
     * @param list<array<string, mixed>> $modules
     *
     * @return array{0: list<array<string, mixed>>, 1: bool} the new list and whether it changed
     */
    public static function applyChange(array $modules, int|string $moduleId, ?string $col, bool $remove): array
    {
        $matches = static fn (array $entry): bool => (string) ($entry['mod'] ?? '') === (string) $moduleId
            && (null === $col || ($entry['col'] ?? null) === $col);

        if ($remove) {
            $kept = array_values(array_filter($modules, static fn (array $e): bool => !$matches($e)));

            return [$kept, \count($kept) !== \count($modules)];
        }

        foreach ($modules as $entry) {
            if ($matches($entry)) {
                return [$modules, false];
            }
        }

        $modules[] = ['mod' => (string) $moduleId, 'col' => (string) $col, 'enable' => '1'];

        return [$modules, true];
    }
}
