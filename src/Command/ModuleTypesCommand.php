<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Which module types this installation offers, and what each one needs.
 *
 * Without this the 45 types are discoverable only by guessing one and reading
 * the error — "provoke a failure to find out what is allowed" is a poor
 * contract for a tool meant to be driven by an agent.
 *
 * The extra requirements are computed the same way ModuleCreateCommand computes
 * them: mandatory fields intersected with the type's palette, which is what
 * DC_Table enforces. So the list answers the question a caller actually has —
 * not "what types exist" alone, but "what do I have to supply for this one".
 *
 * Types a third-party extension registers appear here too, without anything
 * being added, because the palettes are the source.
 */
#[AsCommand(name: 'contao:module:types', description: 'List available front end module types and what each requires')]
class ModuleTypesCommand extends AbstractReadCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        Controller::loadDataContainer('tl_module');

        $dca      = $GLOBALS['TL_DCA']['tl_module'] ?? [];
        $palettes = $dca['palettes'] ?? [];

        $mandatory = [];
        foreach ($dca['fields'] ?? [] as $field => $definition) {
            if ('name' !== $field && !empty($definition['eval']['mandatory'])) {
                $mandatory[] = $field;
            }
        }

        $types = [];
        foreach ($palettes as $type => $palette) {
            if ('__selector__' === $type || 'default' === $type || !\is_string($palette)) {
                continue;
            }
            $inPalette = array_map('trim', preg_split('/[;,]/', $palette) ?: []);
            $types[$type] = array_values(array_intersect($mandatory, $inPalette));
        }
        ksort($types);

        $this->outputRecord([
            'count' => \count($types),
            'types' => $types,
        ]);

        return Command::SUCCESS;
    }
}
