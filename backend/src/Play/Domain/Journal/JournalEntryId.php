<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * Identifies a journal entry. Opaque string (a UUID v7 in production).
 */
final readonly class JournalEntryId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidJournalEntryId
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidJournalEntryId::blank();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
