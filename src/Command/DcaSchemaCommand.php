<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:dca:schema', description: 'Return DCA field definitions for a table')]
class DcaSchemaCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('table', InputArgument::REQUIRED, 'DCA table name (e.g. tl_news)');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        $table = $this->input->getArgument('table');

        Controller::loadDataContainer($table);

        if (!isset($GLOBALS['TL_DCA'][$table])) {
            return $this->outputError(
                "DCA not found for table: $table"
                .(($hint = MissingBundleHint::for($table)) ? "\n".$hint : '')
            );
        }

        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $result = [];

        foreach ($fields as $name => $def) {
            $result[$name] = [
                'label'         => $def['label'][0] ?? $name,
                'inputType'     => $def['inputType'] ?? null,
                'mandatory'     => (bool) ($def['eval']['mandatory'] ?? false),
                'unique'        => (bool) ($def['eval']['unique'] ?? false),
                'maxlength'     => $def['eval']['maxlength'] ?? null,
                'options'       => $this->optionValues($def),
                'optionsSource' => $this->optionsSource($def),
            ];
        }

        $this->outputRecord(['table' => $table, 'fields' => $result]);
        return Command::SUCCESS;
    }

    /**
     * The values a caller may actually set, in Contao's two-and-a-half forms.
     *
     * 🔴 Until 2026-08-31 this was `array_keys($def['options'])` unconditionally,
     * which is right for exactly one of them:
     *
     *   array('de' => 'Deutsch')                   assoc — the key IS the value
     *   array('map_default', 'map_always')         list  — the key is 0, 1, …
     *
     * Contao's own DCAs use the list form almost everywhere, so almost every
     * answer was wrong: `tl_page.sitemap` came back as `[0, 1, 2]` where the
     * DCA declares `map_default, map_always, map_never`.
     *
     * 🎯 **It never showed up because it reads correctly.** Looking at the line
     * you picture the associative form, and there it is right. And the wrong
     * answer looks like an answer — `tl_content` declares `array(1, 2, …, 12)`,
     * whose keys are `0..11`, so the reply is plausible and off by one
     * throughout. A caller building `--set` from it gets rejected by the DCA
     * and goes looking in the wrong place.
     *
     * Reported by the parallel session working on the wienerwandern booking
     * module, which hit it on a table of its own and checked `tl_page` to rule
     * out its own DCA.
     *
     * Nested option groups are flattened: Contao allows
     * `array('Group' => array('a', 'b'))` for an optgroup, and the group name
     * is not something anyone can set either.
     *
     * @param array<string, mixed> $def
     *
     * @return list<string>|null
     */
    private function optionValues(array $def): ?array
    {
        if (!isset($def['options']) || !\is_array($def['options'])) {
            return null;
        }

        return $this->flattenOptions($def['options']);
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return list<string>
     */
    private function flattenOptions(array $options): array
    {
        $values = [];

        foreach ($options as $key => $value) {
            if (\is_array($value)) {
                // An optgroup: the group name is a label, its members are values.
                $values = [...$values, ...$this->flattenOptions($value)];
                continue;
            }

            // List form: the value is the value. Associative: the key is.
            $values[] = \is_int($key) ? (string) $value : (string) $key;
        }

        return array_values(array_unique($values));
    }

    /**
     * Where the options come from — so `null` can be told apart from "none".
     *
     * A field with an `options_callback` or a `foreignKey` has options; they
     * are simply not in the DCA array, and several callbacks need a live
     * DataContainer this command does not have. Reporting a bare `null` for
     * those makes "this field takes any value" and "the values exist but not
     * here" look identical — the same confusion the wrong `options` caused,
     * one step further along.
     *
     * @param array<string, mixed> $def
     */
    private function optionsSource(array $def): ?string
    {
        if (isset($def['options']) && \is_array($def['options'])) {
            return 'static';
        }

        if (isset($def['options_callback'])) {
            return 'callback';
        }

        if (isset($def['foreignKey'])) {
            return 'foreignKey';
        }

        return null;
    }
}
