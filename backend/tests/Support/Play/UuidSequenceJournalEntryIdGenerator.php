<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Domain\Journal\JournalEntryId;

/**
 * Hands out UUIDs <prefix>01, <prefix>02… so tests can name the entries they expect (Doctrine
 * stores ids as UUIDs), or one id again and again once repeat() is called.
 */
final class UuidSequenceJournalEntryIdGenerator implements JournalEntryIdGenerator
{
    private int $next = 1;
    private ?string $repeated = null;

    /**
     * @param string $prefix the first 34 characters of the UUIDs
     */
    public function __construct(
        private readonly string $prefix,
    ) {
    }

    public function repeat(string $id): void
    {
        $this->repeated = $id;
    }

    public function idNumber(int $number): string
    {
        return \sprintf('%s%02d', $this->prefix, $number);
    }

    public function generate(): JournalEntryId
    {
        return JournalEntryId::fromString($this->repeated ?? $this->idNumber($this->next++));
    }
}
