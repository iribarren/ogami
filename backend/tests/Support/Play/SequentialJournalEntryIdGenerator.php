<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Domain\Journal\JournalEntryId;

/**
 * Hands out "entry-001", "entry-002"… so tests can name the entries they expect. The padding
 * keeps string order equal to generation order (entries recorded at the same time sort by id).
 */
final class SequentialJournalEntryIdGenerator implements JournalEntryIdGenerator
{
    private int $next = 1;

    public function generate(): JournalEntryId
    {
        return JournalEntryId::fromString(\sprintf('entry-%03d', $this->next++));
    }
}
