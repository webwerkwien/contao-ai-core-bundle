<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ThemeModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a theme — the root of the theme layer.
 *
 * `--author` is free text, not a user reference. The column is a `text` field
 * in the DCA and Contao's own demo theme carries "Joe Ray Gregory, Sascha
 * Müller, Felix Pfeiffer, …" in it: a credit line, not an ID. Filling it with
 * `resolveAuthorId()` — as every other create command here does for its own
 * `author` column — would put a number where a name belongs.
 *
 * `folders` and `screenshot` are `fileTree` columns and go through `--set` like
 * anywhere else; AbstractWriteCommand converts a UUID string to binary for
 * them. `templates` selects the template subfolder.
 */
#[AsCommand(name: 'contao:theme:create', description: 'Create a theme')]
class ThemeCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('name',   null, InputOption::VALUE_REQUIRED, 'Theme name');
        $this->addOption('author', null, InputOption::VALUE_REQUIRED, 'Author credit line (free text, not a user ID)');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $name   = (string) $this->input->getOption('name');
        $author = (string) $this->input->getOption('author');

        if ('' === $name || '' === $author) {
            return $this->outputError('--name and --author are required');
        }

        $fields = $this->convertFields('tl_theme', $fields);

        $theme         = new ThemeModel();
        $theme->tstamp = time();
        $theme->name   = $name;
        $theme->author = $author;

        foreach ($fields as $key => $value) {
            $theme->$key = $value;
        }

        $theme->save();
        $this->createVersion('tl_theme', (int) $theme->id);

        $this->outputSuccess(['id' => (int) $theme->id, 'name' => $name]);

        return Command::SUCCESS;
    }
}
