<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

/**
 * Identifies a Campaign fact. Opaque string (a UUID v7 in production).
 */
final readonly class FactId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidFact
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidFact::because('A fact id must not be blank.');
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
