<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Contao\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'contao:file:meta', description: 'Update metadata (alt, title, caption, …) on a tl_files record')]
class FileMetaUpdateCommand extends AbstractWriteCommand
{
    /** Fields stored inside the serialized tl_files.meta column (per language). */
    private const META_FIELDS = ['title', 'alt', 'link', 'caption', 'license'];

    /** Fields that are direct columns on tl_files. */
    private const DIRECT_FIELDS = ['name', 'importantPartX', 'importantPartY', 'importantPartWidth', 'importantPartHeight'];

    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'File or folder path relative to Contao root, e.g. files/images/photo.jpg');
        $this->addOption('lang', null, InputOption::VALUE_OPTIONAL, 'Language key for meta fields, must match root-page language (default: en)', 'en');
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

        $allowedFields = array_merge(self::META_FIELDS, self::DIRECT_FIELDS);

        foreach (array_keys($fields) as $key) {
            if (!in_array($key, $allowedFields, true)) {
                return $this->outputError("Field '{$key}' is not an editable metadata field. Allowed: " . implode(', ', $allowedFields));
            }
        }

        $this->framework->initialize();

        $file = FilesModel::findByPath(ltrim($path, '/'));
        if ($file === null) {
            return $this->outputError("No tl_files record found for path: {$path}. Run contao:filesync first.");
        }

        $lang = $this->input->getOption('lang') ?: 'en';

        // Deserialize existing meta, initialising the language entry if absent
        $meta = StringUtil::deserialize($file->meta, true);
        if (!isset($meta[$lang]) || !is_array($meta[$lang])) {
            $meta[$lang] = ['title' => '', 'alt' => '', 'link' => '', 'caption' => '', 'license' => ''];
        }

        $updated = [];

        foreach ($fields as $key => $value) {
            if (in_array($key, self::META_FIELDS, true)) {
                $meta[$lang][$key] = $value;
            } else {
                // Direct tl_files column (name, importantPart*)
                $file->$key = $value;
            }
            $updated[] = $key;
        }

        $file->meta   = serialize($meta);
        $file->tstamp = time();
        $file->save();

        $this->outputSuccess(['path' => $path, 'lang' => $lang, 'updated' => $updated]);
        return Command::SUCCESS;
    }
}
