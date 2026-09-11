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
                'optionsTarget' => $this->optionsTarget($def),
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

    /**
     * Where a `foreignKey` field's options actually live.
     *
     * `optionsSource` says *that* the values come from another table;
     * this says **which one**. Without it the answer stops one step short of
     * useful: a caller knows it may not invent a value and still cannot find
     * out which values exist.
     *
     * 🔴 **The gap this closes, measured on 2026-09-11.** A field added to
     * `tl_page` by an extension (`consho_shop`, `foreignKey` →
     * `tl_consho_shop.title`) accepts any number through `--set`. The back end
     * prevents a dangling reference with its select list, the database does
     * not. Of 1183 fields in a stock 5.7.13 with all five optional bundles,
     * **79 declare a `foreignKey`** — every one of them the same shape.
     *
     * Contao writes the declaration as `table.labelField`, and both halves are
     * needed: the table to look the values up, the field for the label a human
     * would recognise. Reported split rather than raw so a caller does not
     * parse a string this command has already parsed.
     *
     * ⚠️ **Only the declared form is reported, and only when it parses.**
     * `foreignKey` may carry an SQL expression rather than a plain
     * `table.field`. Counted on the same installation: of 21 distinct
     * declarations exactly one is such a case —
     * `tl_member.CONCAT(firstname," ",lastname)`. The label is computed, so
     * there is no column to name, and the answer stays `null` rather than
     * reporting a field that does not exist. `optionsSource` still says
     * `foreignKey`, so the caller learns the options are elsewhere either way.
     *
     * @param array<string, mixed> $def
     *
     * @return array{table: string, labelField: string}|null
     */
    private function optionsTarget(array $def): ?array
    {
        $fk = $def['foreignKey'] ?? null;

        if (!\is_string($fk) || !preg_match('/^([A-Za-z0-9_]+)\.([A-Za-z0-9_]+)$/', $fk, $m)) {
            return null;
        }

        return ['table' => $m[1], 'labelField' => $m[2]];
    }
}
