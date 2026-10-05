<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A user's email address, normalized (trimmed, lower case) so that it is unique
 * regardless of how it was typed.
 */
final readonly class Email
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidEmail
     */
    public static function fromString(string $address): self
    {
        $normalized = strtolower(trim($address));

        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmail::malformed($address);
        }

        return new self($normalized);
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
