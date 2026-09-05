<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * The back end's Settings module — the one entry that is not a table.
 *
 * `tl_settings` has no database table behind it. Its data container is
 * `DC_File`, and the values live in `system/config/localconfig.php` as
 * `$GLOBALS['TL_CONFIG'][…]`. That is why `record:list tl_settings` answers
 * "No readable columns" — correctly, since there is nothing to read from a
 * schema — and why this needs a command of its own rather than a wrapper.
 *
 * Two values are reported per field, and the difference matters:
 *
 *  - **`value`** — what `Config::get()` answers, which is what Contao actually
 *    uses. It may come from the bundle default rather than from anywhere the
 *    administrator set.
 *  - **`persisted`** — whether `localconfig.php` carries an override at all.
 *
 * A field can read `30` and be persisted `false`, meaning nobody chose 30; it
 * is simply Contao's default and will move if the default moves. Reporting
 * only the effective value would make those two cases indistinguishable.
 *
 * The field list comes from the DCA, so a setting an extension registers is
 * included without a change here.
 */
#[AsCommand(name: 'contao:settings:read', description: 'Read the global Contao settings (localconfig.php)')]
class SettingsReadCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        // No arguments: the settings are a single record by definition.
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        if (!isset($GLOBALS['TL_DCA']['tl_settings']['fields'])) {
            Controller::loadDataContainer('tl_settings');
        }

        $dca = $GLOBALS['TL_DCA']['tl_settings']['fields'] ?? [];

        if ([] === $dca) {
            return $this->outputError('The tl_settings data container has no fields.');
        }

        $persisted = $this->persistedKeys();
        $settings  = [];

        foreach (array_keys($dca) as $field) {
            $field = (string) $field;

            $settings[$field] = [
                'value'     => Config::get($field),
                'persisted' => \in_array($field, $persisted, true),
                'mandatory' => (bool) ($dca[$field]['eval']['mandatory'] ?? false),
                'inputType' => $dca[$field]['inputType'] ?? null,
            ];
        }

        $this->outputRecord([
            'file'     => 'system/config/localconfig.php',
            'count'    => \count($settings),
            'settings' => $settings,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Keys `localconfig.php` actually overrides.
     *
     * Read from the file rather than inferred from a comparison against the
     * defaults: two identical values would be indistinguishable that way, and
     * "somebody deliberately set this to the default" is exactly the case worth
     * being able to see.
     *
     * @return list<string>
     */
    private function persistedKeys(): array
    {
        try {
            $root = System::getContainer()->getParameter('kernel.project_dir');
        } catch (\Throwable) {
            return [];
        }

        $file = $root . '/system/config/localconfig.php';

        if (!is_readable($file)) {
            return [];
        }

        preg_match_all(
            '/\$GLOBALS\[\'TL_CONFIG\'\]\[\'([A-Za-z0-9_]+)\'\]/',
            (string) file_get_contents($file),
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }
}
