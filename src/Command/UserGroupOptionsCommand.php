<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\System;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Which values the permission fields of `tl_user_group` accept.
 *
 * 🎯 The reason this command exists is the failure mode of the fields it
 * describes. A wrong module name in `--set modules=…` does not fail: the value
 * is stored, and the permission is simply never granted. There is no error to
 * read, so "guess and see what happens" — the fallback for `module create`
 * before `module types` — does not even work here.
 *
 * Everything comes from the DCA and the registries Contao itself reads, so an
 * extension's back end module or content element shows up without a change
 * here. Nothing in this command is a hard-coded list of Contao's own values.
 *
 * Two of the sets are per-table and much larger than the rest, so they sit
 * behind `--table`:
 *
 *   contao:user-group:options                  → modules, elements, page types, …
 *   contao:user-group:options --table=tl_news  → cud and alexf for that table
 *
 * `cud` reads `config.permissions`, which Contao's own CudPermissionListener
 * fills with create/update/delete for every editable DC_Table — a DCA may
 * override it, and then this reports the override. `alexf` uses
 * `DataContainer::isFieldExcluded()`, the same test the back end applies.
 */
#[AsCommand(name: 'contao:user-group:options', description: 'List the accepted values for user group permission fields')]
class UserGroupOptionsCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'table', null, InputOption::VALUE_REQUIRED,
            'Report the per-table sets (cud, alexf) for this table, e.g. tl_news',
        );
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        Controller::loadDataContainer('tl_user_group');

        $table = (string) ($this->input->getOption('table') ?? '');

        if ('' !== $table) {
            return $this->outputForTable($table);
        }

        $dca = $GLOBALS['TL_DCA']['tl_user_group']['fields'] ?? [];

        $this->outputRecord([
            'modules'         => $this->backendModules(),
            'themes'          => $dca['themes']['options'] ?? [],
            'elements'        => array_map('array_keys', $GLOBALS['TL_CTE'] ?? []),
            'fields'          => $dca['fields']['options'] ?? array_keys($GLOBALS['TL_FFL'] ?? []),
            'frontendModules' => array_map('array_keys', $GLOBALS['FE_MOD'] ?? []),
            'alpty'           => array_keys($GLOBALS['TL_PTY'] ?? []),
            'fop'             => $dca['fop']['options'] ?? [],
            'imageSizes'      => $this->imageSizes(),
            'perTable'        => 'cud and alexf are per-table: pass --table=tl_news',
            'format'          => [
                'pagemounts' => 'page IDs, comma-separated',
                'filemounts' => 'file UUIDs, comma-separated',
                'forms'      => 'tl_form IDs',
                'amg'        => 'tl_member_group IDs',
                'cud'        => 'tl_table::operation',
                'alexf'      => 'tl_table::field',
            ],
        ]);

        return Command::SUCCESS;
    }

    private function outputForTable(string $table): int
    {
        if (!preg_match('/^tl_[a-z0-9_]+$/', $table)) {
            return $this->outputError(\sprintf('Not a table name: %s', $table));
        }

        Controller::loadDataContainer($table);
        $dca = $GLOBALS['TL_DCA'][$table] ?? null;

        if (null === $dca || !\is_array($dca['fields'] ?? null)) {
            return $this->outputError(\sprintf('No data container for table: %s', $table));
        }

        $cud = [];
        foreach ($dca['config']['permissions'] ?? [] as $operation) {
            $cud[] = $table . '::' . $operation;
        }

        $alexf = [];
        foreach (array_keys($dca['fields']) as $field) {
            if (DataContainer::isFieldExcluded($table, (string) $field)) {
                $alexf[] = $table . '::' . $field;
            }
        }

        $this->outputRecord([
            'table' => $table,
            'cud'   => $cud,
            'alexf' => $alexf,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Back end modules a group can be granted, grouped as the back end groups them.
     *
     * Mirrors tl_user_group::getModules(): modules flagged
     * `disablePermissionChecks` are dropped, because granting them is
     * meaningless — every user reaches them regardless.
     *
     * @return array<string, list<string>>
     */
    private function backendModules(): array
    {
        $modules = [];

        foreach ($GLOBALS['BE_MOD'] ?? [] as $group => $entries) {
            if (!\is_array($entries) || [] === $entries) {
                continue;
            }

            $names = [];
            foreach ($entries as $name => $config) {
                if (true === ($config['disablePermissionChecks'] ?? false)) {
                    continue;
                }
                $names[] = (string) $name;
            }

            if ([] !== $names) {
                $modules[$group] = $names;
            }
        }

        return $modules;
    }

    /**
     * Image size options, or an empty list when the service cannot answer.
     *
     * The only entry here that needs a container service rather than a global.
     * An empty list is the honest answer if it is unavailable — better than
     * failing the whole command over one of nine sets.
     *
     * @return array<string, mixed>
     */
    private function imageSizes(): array
    {
        try {
            $sizes = System::getContainer()->get('contao.image.sizes');

            return \is_object($sizes) && method_exists($sizes, 'getAllOptions')
                ? $sizes->getAllOptions()
                : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
