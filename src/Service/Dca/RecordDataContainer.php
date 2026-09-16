<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\DataContainer;
use Contao\DC_Table;

/**
 * A DC_Table for calling Contao's own callbacks from the console, answering with a
 * given record instead of asking the permission voters.
 *
 * `getCurrentRecord()` checks read permission and fails without a back-end user;
 * `getActiveRecord()` and the palette code go through it. Both are overridden, and the
 * constructor — which needs a request — is replaced. The signatures are identical in
 * Contao 5.3, 5.7 and 6.0. Used by OptionsResolver (options callbacks) and
 * DcaPaletteCommand (DataContainer::getPalette()).
 *
 * A factory rather than a named subclass: a class under src/ extending DC_Table would
 * be picked up as a service and fail to autowire.
 */
final class RecordDataContainer
{
    /**
     * @param array<string, mixed> $record
     */
    public static function create(string $table, int $id, array $record): DataContainer
    {
        return new class($table, $id, $record) extends DC_Table {
            /**
             * @param array<string, mixed> $record
             */
            public function __construct(string $table, int $id, private readonly array $record)
            {
                $this->strTable = $table;
                $this->intId    = $id;
            }

            public function getCurrentRecord(int|string|null $id = null, string|null $table = null): array|null
            {
                return [] === $this->record ? null : $this->record;
            }

            public function getActiveRecord(): array|null
            {
                return [] === $this->record ? null : $this->record;
            }
        };
    }
}
