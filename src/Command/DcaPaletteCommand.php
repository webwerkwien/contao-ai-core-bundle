<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\RecordDataContainer;

/**
 * The fields of the palette a record gets, and which are mandatory (v0.16.0).
 *
 * `contao:dca:schema` answers mandatory for every field of a table at once — for
 * tl_page 13 fields across all page types. This asks Contao's own
 * DataContainer::getPalette() with the `--set` values as the record, so selectors,
 * sub-palettes and onpalette callbacks decide, as in the back end. See
 * DcaPaletteCommandTest.
 */
#[AsCommand(name: 'contao:dca:palette', description: 'List the palette fields of a record, and its mandatory ones')]
class DcaPaletteCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::REQUIRED, 'DCA table, e.g. tl_page')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Record values that select the palette, e.g. type=root, enableCsp=1');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();

        $table = (string) $this->input->getArgument('table');
        Controller::loadDataContainer($table);

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            return $this->outputError("DCA not found for table: {$table}");
        }

        $record = [];
        foreach ((array) $this->input->getOption('set') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $record[trim($key)] = $value;
        }

        try {
            $dc      = RecordDataContainer::create($table, 0, $record);
            $palette = (string) $dc->getPalette();
            // The record as the palette saw it — with the column defaults laid under the
            // given values, as soon as any are given (Fable review before v1.7.0).
            $seen    = $dc->getCurrentRecord() ?? [];
        } catch (\Throwable $e) {
            return $this->outputError("The palette of {$table} could not be built on the console: " . $e->getMessage());
        }

        $fields = self::fieldsOf($palette);

        $this->outputRecord([
            'status'    => 'ok',
            'table'     => $table,
            'record'    => $record,
            'fields'    => $fields,
            'mandatory' => self::mandatoryOf($fields, $GLOBALS['TL_DCA'][$table]['fields']),
        ] + self::missingSelectors($table, $fields, $seen));

        return Command::SUCCESS;
    }

    /**
     * `selectors`: the fields of this palette that choose another one and have no value
     * in the record (v1.7.0) — `tl_content` without a type answers `["type"]` alone, and
     * this says so instead of leaving a caller to guess. Select and radio only: a
     * checkbox that opens a sub-palette is a switch, not a missing choice; nor is a field
     * whose blank is an option (`tl_form_field.rgxp`: "no validation"). Always in the
     * answer, `[]` when nothing is missing, so a caller can tell this core from an older one.
     *
     * @param list<string>         $fields
     * @param array<string, mixed> $record
     *
     * @return array{selectors: list<string>}
     */
    public static function missingSelectors(string $table, array $fields, array $record): array
    {
        $selectors = $GLOBALS['TL_DCA'][$table]['palettes']['__selector__'] ?? [];

        if (!\is_array($selectors)) {
            return ['selectors' => []];
        }

        $missing = array_values(array_filter(
            $fields,
            static fn (string $field): bool => \in_array($field, $selectors, true)
                && '' === (string) ($record[$field] ?? '')
                && \in_array($GLOBALS['TL_DCA'][$table]['fields'][$field]['inputType'] ?? null, ['select', 'radio'], true)
                && empty($GLOBALS['TL_DCA'][$table]['fields'][$field]['eval']['includeBlankOption']),
        ));

        return ['selectors' => $missing];
    }

    /**
     * The field names of a palette string — legends, sub-palette markers and
     * `:hide` stripped.
     *
     * @return list<string>
     */
    public static function fieldsOf(string $palette): array
    {
        $fields = [];

        foreach (preg_split('/[;,]/', $palette) ?: [] as $part) {
            $part = trim($part);

            // `{legend}`, `{legend:hide}`, and the `[selector]` / `[EOF]` markers
            // Contao puts around an included sub-palette.
            if ('' === $part || str_starts_with($part, '{') || str_starts_with($part, '[')) {
                continue;
            }

            $fields[] = $part;
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param list<string>                        $fields
     * @param array<string, array<string, mixed>> $dca
     *
     * @return list<string>
     */
    public static function mandatoryOf(array $fields, array $dca): array
    {
        return array_values(array_filter(
            $fields,
            static fn (string $field): bool => (bool) ($dca[$field]['eval']['mandatory'] ?? false),
        ));
    }
}
