<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

abstract class AbstractModelUpdateCommand extends AbstractWriteCommand
{
    abstract protected function modelClass(): string;
    abstract protected function entityName(): string;

    public function __construct(protected readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::OPTIONAL, $this->entityName() . ' ID');
        $this->addOption(
            'ids', null,
            InputOption::VALUE_REQUIRED,
            'Comma-separated IDs to apply the same --set values to, e.g. --ids=39,40,41. '
            . 'Mutually exclusive with the ID argument. Each record is versioned and logged '
            . 'individually, exactly as a single update would be — only the connection is shared.',
        );
    }

    /**
     * Turn the raw `--ids` value into a list of record IDs.
     *
     * Strict on purpose. The bulk run of 2026-08-29 went wrong precisely because
     * a silent skip looked like a success: 174 IDs went in, one record came out
     * changed, and the report said "1 succeeded, 0 failed". Anything that is not
     * a positive integer is therefore named and refused rather than dropped.
     *
     * @return list<int> in the given order, duplicates removed
     *
     * @throws \InvalidArgumentException on an empty list or a malformed entry
     */
    public function parseIdList(string $raw): array
    {
        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue; // a trailing or doubled comma is a typo, not an instruction
            }
            if (!ctype_digit($part) || '0' === ltrim($part, '0') || '' === ltrim($part, '0')) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a valid record ID.', $part));
            }
            $ids[] = (int) $part;
        }

        if ([] === $ids) {
            throw new \InvalidArgumentException('--ids did not contain a single record ID.');
        }

        return array_values(array_unique($ids));
    }

    /**
     * Hook for subclasses to transform field values before write — e.g. wrap
     * Contao input-unit fields like `tl_news.headline` in their canonical
     * serialized array shape so create and update produce identical column
     * payloads. Default: return $fields unchanged.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function preProcessFields(array $fields, object $record): array
    {
        return $fields;
    }

    /**
     * Default unit for inputUnit fields (headline) when neither an explicit
     * value nor an existing record value provides one. Subclasses override
     * where Contao's field default differs (e.g. News → h1).
     */
    protected function defaultInputUnit(): string
    {
        return 'h2';
    }

    protected function doExecute(array $fields): int
    {
        $idArgument = (string) ($this->input->getArgument('id') ?? '');
        $idsOption  = (string) ($this->input->getOption('ids') ?? '');

        if ('' !== $idArgument && '' !== $idsOption) {
            return $this->outputError('Give either an ID argument or --ids, not both.');
        }
        if ('' === $idArgument && '' === $idsOption) {
            return $this->outputError('No record specified. Give an ID argument or --ids=1,2,3');
        }
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        if ('' !== $idsOption) {
            try {
                $ids = $this->parseIdList($idsOption);
            } catch (\InvalidArgumentException $e) {
                return $this->outputError($e->getMessage());
            }

            return $this->updateMany($ids, $fields);
        }

        return $this->updateOne((int) $idArgument, $fields);
    }

    /**
     * Apply the same values to a list of records over one connection.
     *
     * Deliberately not a transaction: a bulk edit is N independent edits that
     * happen to share a connection, and rolling 173 good writes back because the
     * 174th record was deleted meanwhile helps nobody. What matters is that the
     * outcome is *reported* — the failure mode this exists to prevent was a run
     * that changed one record out of 174 and exited 0.
     *
     * @param list<int>            $ids
     * @param array<string, mixed> $fields
     */
    protected function updateMany(array $ids, array $fields): int
    {
        $this->framework->initialize();

        $succeeded = [];
        $failed    = [];

        foreach ($ids as $id) {
            try {
                $updated = $this->applyToRecord($id, $fields);
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'message' => $e->getMessage()];
                continue;
            }

            if (null === $updated) {
                $failed[] = ['id' => $id, 'message' => $this->entityName() . " not found: $id"];
                continue;
            }

            $succeeded[] = $id;
            // Same audit rows a single update writes. The per-record history is
            // the entire reason for going through the console instead of SQL.
            $this->logSuccess(['id' => $id, 'updated' => $updated]);
        }

        $this->output->writeln(json_encode([
            'status'    => [] === $failed ? 'ok' : 'partial',
            'total'     => \count($ids),
            'succeeded' => \count($succeeded),
            'failed'    => \count($failed),
            'ids'       => $succeeded,
            'errors'    => $failed,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        // A non-zero exit is what lets a shell loop notice. The 2026-08-29 run
        // reported success while doing almost nothing.
        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function updateOne(int $id, array $fields): int
    {
        $this->framework->initialize();

        $updated = $this->applyToRecord($id, $fields);
        if (null === $updated) {
            return $this->outputError($this->entityName() . " not found: $id");
        }

        $this->outputSuccess(['id' => $id, 'updated' => $updated]);

        return Command::SUCCESS;
    }

    /**
     * Write the given values to one record.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<string>|null the written field names, or null when the record does not exist
     */
    protected function applyToRecord(int $id, array $fields): ?array
    {
        $class  = $this->modelClass();
        $record = $class::findById($id);

        if ($record === null) {
            return null;
        }

        // The record is read here for the DCA conversions below, which need the
        // stored value to preserve an existing inputUnit, and again inside the
        // writer. Contao's Model\Registry serves the second lookup from memory,
        // so this costs no query — and it keeps the writer free of entity knowledge.
        $fields = $this->preProcessFields($fields, $record);
        // inputUnit fields (headline): serialize to {value, unit}; unit via
        // <field>_unit companion / JSON value / existing record value / default.
        $fields = $this->convertInputUnitFields($class::getTable(), $fields, $this->defaultInputUnit(), $record);
        // Binary UUIDs for fileTree fields (singleSRC etc.) and serialized lists
        // for multi-value fields (news_archives, groups, cud, ...). Both are
        // DCA-driven, so this covers every table without entity knowledge — and
        // it is the same call the create commands make, on purpose.
        // The id is passed so the `unique` check excludes the record being
        // edited. Without it, saving a unique field without changing it would
        // find itself and refuse — which is why the check was create-only until
        // 2026-09-01. `DC_Table::save()` passes the same id for the same reason.
        $fields = $this->convertFields($class::getTable(), $fields, $id);

        return $this->writer()->update(
            $class::getTable(),
            $id,
            $fields,
            $this->resolveOperator(),
        );
    }
}
