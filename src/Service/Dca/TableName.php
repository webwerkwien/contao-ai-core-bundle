<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

/**
 * Whether a string can be a table name before it goes anywhere near SQL.
 *
 * A name, not a prefix: until v1.9.2 four commands demanded `tl_`, and every
 * table the Catalog Manager creates was out of reach — it refuses the prefix for
 * its own catalogs ("tl_" prefix is not allowed), so a catalog table is always
 * named something else. Whether the name belongs to a Contao table is the DCA's
 * to say, and every caller checks it right after this.
 */
final class TableName
{
    /** MySQL identifiers end at 64 characters. */
    public static function isValid(string $table): bool
    {
        return 1 === preg_match('/^[a-z0-9_]{1,64}$/i', $table);
    }
}
