<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;

/**
 * The values a field offers, asked from the installation (v0.16.0).
 *
 * Static options come from the DCA; an `options_callback` is called as the back end
 * would, with the `--set` pairs as the record — `--set type=text` for the templates
 * of a text element. A callback that needs a request or a user cannot run here and
 * is reported with its own message. See OptionsResolverTest.
 */
#[AsCommand(name: 'contao:dca:options', description: 'List the values a field offers, resolved on the installation')]
class DcaOptionsCommand extends AbstractReadCommand
{
    use ReadsDcaOptions;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly OptionsResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::REQUIRED, 'DCA table, e.g. tl_page')
            ->addArgument('field', InputArgument::REQUIRED, 'Field, e.g. type')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Record values the options depend on, e.g. type=text');
    }

    protected function doExecute(): int
    {
        // Callbacks are services and read the DCA — both need the framework up.
        $this->framework->initialize();

        $table  = (string) $this->input->getArgument('table');
        $field  = (string) $this->input->getArgument('field');
        $record = [];

        foreach ((array) $this->input->getOption('set') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $record[trim($key)] = $value;
        }

        $options = $this->resolver->options($table, $field, $record);

        if (null === $options) {
            $why = $this->resolver->lastError($table, $field);

            return $this->outputError(null === $why
                ? "No options for {$table}.{$field}: the field has neither options nor an options callback."
                : "The options of {$table}.{$field} could not be resolved on the console: {$why}");
        }

        $this->outputRecord([
            'status'  => 'ok',
            'table'   => $table,
            'field'   => $field,
            'options' => $options,
            // With the field's eval, as OptionsResolver::values() — an `isAssociative`
            // list answers its indices, not its labels (Nr. 53).
            'values'  => $this->optionValues([
                'options' => $options,
                'eval'    => $GLOBALS['TL_DCA'][$table]['fields'][$field]['eval'] ?? [],
            ]),
        ]);

        return Command::SUCCESS;
    }
}
