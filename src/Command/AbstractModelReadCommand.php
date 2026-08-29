<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

abstract class AbstractModelReadCommand extends AbstractReadCommand
{
    /** FQCN of the Contao Model class, e.g. \Contao\ArticleModel::class */
    abstract protected function modelClass(): string;

    /** Human-readable entity name for error messages, e.g. 'Article' */
    abstract protected function entityName(): string;

    public function __construct(protected readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, $this->entityName() . ' ID');
    }

    protected function doExecute(): int
    {
        $this->framework->initialize();
        $id    = (int) $this->input->getArgument('id');
        $class = $this->modelClass();
        $record = $class::findById($id);

        if ($record === null) {
            return $this->outputError($this->entityName() . " not found: $id");
        }

        $row = $this->convertFileTreeFieldsToUuid($class::getTable(), $record->row());

        $this->outputRecord($this->postProcessRow($row));
        return Command::SUCCESS;
    }

    /**
     * Turn Contao's binary file references back into UUID strings.
     *
     * The inverse of AbstractWriteCommand::convertFileTreeFields(), which has
     * converted UUID strings to binary on write since v0.2.1. The read side had
     * no counterpart, so a `fileTree` column left the command as its raw 16
     * bytes — and `outputRecord()` encodes with JSON_INVALID_UTF8_SUBSTITUTE,
     * which replaces every byte that is not valid UTF-8 with U+FFFD. The
     * reference was therefore destroyed in PHP, before anything left the server:
     *
     *     "navigationImage": "GW\u{FFFD}V+8\x11\u{FFFD}\u{FFFD}\0\0(\u{FFFD}T"
     *
     * That cost a caller any way of telling which file a record points at, and
     * on 2026-08-29 it also crashed `contao-ai-cli page read 98`, whose cp1252
     * stdout could not print the replacement characters it had been handed.
     *
     * Values that are not a binary UUID are left untouched — an empty column
     * means "no file", and re-running over an already converted row is a no-op.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function convertFileTreeFieldsToUuid(string $table, array $row): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        foreach ($row as $key => $value) {
            if (($dca[$key]['inputType'] ?? null) !== 'fileTree' || !\is_string($value) || '' === $value) {
                continue;
            }

            if ((bool) ($dca[$key]['eval']['multiple'] ?? false)) {
                $uuids = StringUtil::deserialize($value);
                if (!\is_array($uuids)) {
                    continue;
                }
                $row[$key] = array_values(array_map(
                    static fn ($uuid) => \is_string($uuid) && Validator::isBinaryUuid($uuid)
                        ? StringUtil::binToUuid($uuid)
                        : $uuid,
                    $uuids,
                ));
                continue;
            }

            if (Validator::isBinaryUuid($value)) {
                $row[$key] = StringUtil::binToUuid($value);
            }
        }

        return $row;
    }

    /**
     * Override to transform the raw row before output.
     * Default: return row unchanged.
     */
    protected function postProcessRow(array $row): array
    {
        return $row;
    }
}
