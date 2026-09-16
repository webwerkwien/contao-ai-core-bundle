<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\StringUtil;
use Contao\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\StructuredFields;

abstract class AbstractReadCommand extends Command
{
    use JsonErrorBoundary;

    protected InputInterface $input;
    protected OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input  = $input;
        $this->output = $output;

        return $this->guarded($output, fn (): int => $this->doExecute());
    }

    abstract protected function doExecute(): int;

    protected function outputRecord(array $data): void
    {
        $this->output->writeln(json_encode(
            ['status' => 'ok'] + $data,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    /**
     * Turn Contao's binary file references back into UUID strings.
     *
     * The inverse of AbstractWriteCommand::convertFileTreeFields(), which has
     * converted UUID strings to binary on write since v0.2.1. The read side had
     * no counterpart, so a `fileTree` column left the command as its raw 16
     * bytes — and outputRecord() encodes with JSON_INVALID_UTF8_SUBSTITUTE,
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
     * This lives on AbstractReadCommand rather than AbstractModelReadCommand
     * because the conversion is driven by the DCA, not by a Model: it takes a
     * table name and a row. It sat one class lower until 2026-08-31, which left
     * contao:record:list — the one read command that accepts *any* table, and
     * therefore the one most likely to be pointed at an unfamiliar fileTree
     * field — returning exactly the destroyed values v0.2.15 had just fixed
     * everywhere else. RecordListTool in the backend bundle passed them on.
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
            // A binary(16) column holds a UUID whatever its widget — tl_files.uuid
            // and tl_files.pid have none at all. Without this they left as raw
            // bytes, which JSON turned into null (v0.13.0, measured on c5).
            if (\is_string($value) && 16 === \strlen($value) && self::isBinary16Column($dca[$key]['sql'] ?? null)) {
                $row[$key] = StringUtil::binToUuid($value);
                continue;
            }

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
     * Unpack every field Contao stores as a serialized array (v0.16.0).
     *
     * Until then only `content read` unpacked `headline`, by hand; `layout read`,
     * `module read`, `record list` and the rest answered the raw PHP serialization, and
     * a caller had to parse it. Now every read answers arrays for these fields, and
     * writing accepts them as JSON. See StructuredFieldRoundTripTest.
     *
     * A value that does not unserialize to an array is left as it is.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function convertStructuredFieldsForRead(string $table, array $row): array
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            Controller::loadDataContainer($table);
        }
        $dca = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        foreach ($row as $key => $value) {
            if (!\is_string($value) || '' === $value || !\is_array($dca[$key] ?? null) || !StructuredFields::storesArray($dca[$key])) {
                continue;
            }

            $unserialized = @unserialize($value, ['allowed_classes' => false]);

            if (\is_array($unserialized)) {
                $row[$key] = $unserialized;
            }
        }

        return $row;
    }

    /**
     * Whether a DCA `sql` declaration is a 16-byte binary column — the shape
     * Contao gives every UUID column, in either declaration form.
     */
    private static function isBinary16Column(mixed $sql): bool
    {
        if (\is_string($sql)) {
            return 1 === preg_match('/^\s*binary\s*\(\s*16\s*\)/i', $sql);
        }

        return \is_array($sql)
            && 'binary' === strtolower((string) ($sql['type'] ?? ''))
            && 16 === (int) ($sql['length'] ?? 0);
    }

    protected function outputError(string $message, int $code = 1): int
    {
        $this->output->writeln(json_encode([
            'status'  => 'error',
            'message' => $message,
            'code'    => $code,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return Command::FAILURE;
    }
}
