<?php
namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:file:meta', description: 'Update metadata (alt, title, caption, …) on a tl_files record')]
class FileMetaUpdateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder path relative to Contao root, e.g. files/images/photo.jpg');
    }

    protected function doExecute(array $fields): int
    {
        $path = $this->input->getOption('path');
        if (!$path) {
            return $this->outputError('--path is required');
        }

        if (empty($fields)) {
            return $this->outputError('At least one --set FIELD=VALUE is required');
        }

        $this->framework->initialize();

        $file = FilesModel::findByPath(ltrim($path, '/'));
        if ($file === null) {
            return $this->outputError("No tl_files record found for path: {$path}. Run contao:filesync first.");
        }

        $allowedFields = ['alt', 'title', 'caption', 'name', 'importantPartX', 'importantPartY', 'importantPartWidth', 'importantPartHeight'];
        $updated = [];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowedFields, true)) {
                return $this->outputError("Field '{$key}' is not an editable metadata field. Allowed: " . implode(', ', $allowedFields));
            }
            $file->$key = $value;
            $updated[]  = $key;
        }

        $file->tstamp = time();
        $file->save();

        $this->outputSuccess(['path' => $path, 'updated' => $updated]);
        return Command::SUCCESS;
    }
}
