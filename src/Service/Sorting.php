<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

/**
 * Where a record created at the end of its siblings goes.
 *
 * Contao's step between two sorting values is 128 (DC_Table::getNewPosition()).
 * One place for the rule, used by the create commands (AbstractWriteCommand::
 * nextSorting()) and the cloners, so the two cannot drift apart — see
 * SortingOnCreateTest and PageUrlRulesWiringTest.
 */
final class Sorting
{
    public const STEP = 128;

    public static function after(?int $max): int
    {
        return ($max ?? 0) + self::STEP;
    }
}
