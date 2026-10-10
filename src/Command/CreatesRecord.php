<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

/**
 * A write command that inserts a record.
 *
 * AbstractWriteCommand runs it in one transaction (v1.4.0): the record's oncreate and
 * onsubmit callbacks run after its INSERT, and one that refuses must take the record
 * with it. RecordIdReuseTest keeps every command that marks a first version as created
 * carrying this interface.
 */
interface CreatesRecord
{
}
