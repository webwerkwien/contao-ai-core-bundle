<?php

// ⚠️ Deliberately WITHOUT declare(strict_types=1) — the only file of this bundle.
//
// Whether PHP coerces scalar arguments is decided by the file that makes the call.
// DC_Table has no strict_types, so Contao calls a callback in coercive mode: the undo
// ID it hands to ondelete is a string, and `RecordPreviewListener::
// storePrecompiledRecordPreview(DataContainer $dc, string $undoId)` takes it; an
// extension typing `int $insertId` gets the string insert ID turned into an int.
// Called from a strict file, the same call is a TypeError — measured on c5 (Contao
// 6.0) on 2026-10-10, the first delete after DcaCallbacks went live.
//
// Calling from here reproduces Contao's mode for every callback, whatever the types.

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\System;

/**
 * Calls a DCA callback the way DC_Table does: `[class or service, method]` through
 * System::importStatic(), anything else callable directly.
 */
final class CallbackInvoker
{
    /**
     * @param list<mixed> $arguments
     *
     * @return bool false when the entry is neither — DC_Table skips it as well
     */
    public static function invoke(mixed $callback, array $arguments): bool
    {
        if (\is_array($callback)) {
            System::importStatic($callback[0])->{$callback[1]}(...$arguments);

            return true;
        }

        if (\is_callable($callback)) {
            $callback(...$arguments);

            return true;
        }

        return false;
    }

    /**
     * A callback whose return value is the result — a save_callback returns the value
     * to store. An entry that is neither form hands $passThrough back unchanged.
     *
     * @param list<mixed> $arguments
     */
    public static function call(mixed $callback, array $arguments, mixed $passThrough): mixed
    {
        if (\is_array($callback)) {
            return System::importStatic($callback[0])->{$callback[1]}(...$arguments);
        }

        if (\is_callable($callback)) {
            return $callback(...$arguments);
        }

        return $passThrough;
    }
}
