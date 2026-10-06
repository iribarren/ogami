<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Journal\JournalEntryId;

/**
 * Port: hands out a new, unique JournalEntryId. Callers generate the id before dispatching a
 * Record* command, because commands return nothing, and then read the entry by that id.
 */
interface JournalEntryIdGenerator
{
    public function generate(): JournalEntryId;
}
