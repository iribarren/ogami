<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * What a journal entry holds. Each kind is an immutable value object; toArray() is its canonical
 * stored shape (with a "kind" field) and JournalEntryContents::fromArray() rebuilds it.
 */
interface JournalEntryContent
{
    /**
     * "note", "roll", "oracle-table" or "likelihood".
     */
    public function kind(): string;

    /**
     * @return array<string, mixed> the canonical JSON shape, "kind" first
     */
    public function toArray(): array;
}
