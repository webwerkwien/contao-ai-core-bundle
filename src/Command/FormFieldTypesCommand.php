<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Which form field types this installation offers, and what each one needs.
 *
 * The same reasoning as `contao:module:types`: without this the twenty types
 * are discoverable only by guessing one and reading the error, which is a poor
 * contract for a tool meant to be driven by an agent.
 *
 * The requirements are computed the way `FormFieldCreateCommand` computes them
 * — mandatory fields intersected with the type's palette, which is what
 * `DC_Table` enforces. So the answer covers the question a caller actually has:
 * not "what types exist", but "what do I have to supply for this one".
 *
 * A type a third-party extension registers appears here too, because the
 * palettes are the source and nothing here holds a list of Contao's own.
 */
#[AsCommand(name: 'contao:form-field:types', description: 'List available form field types and what each requires')]
class FormFieldTypesCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        Controller::loadDataContainer('tl_form_field');

        $dca      = $GLOBALS['TL_DCA']['tl_form_field'] ?? [];
        $palettes = $dca['palettes'] ?? [];

        $mandatory = [];
        foreach ($dca['fields'] ?? [] as $field => $definition) {
            if ('type' !== $field && !empty($definition['eval']['mandatory'])) {
                $mandatory[] = (string) $field;
            }
        }

        $types = [];
        foreach ($palettes as $type => $palette) {
            if ('__selector__' === $type || 'default' === $type || !\is_string($palette)) {
                continue;
            }
            $inPalette      = array_map('trim', preg_split('/[;,]/', $palette) ?: []);
            $types[$type]   = array_values(array_intersect($mandatory, $inPalette));
        }
        ksort($types);

        $this->outputRecord([
            'count' => \count($types),
            'types' => $types,
            'note'  => 'options takes a short form: "mrs=Mrs.|mr=Mr." or "red|green|blue".',
        ]);

        return Command::SUCCESS;
    }
}
