<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Identifies a User. Opaque string (a UUID in production); generated through
 * the Application's UserIdGenerator port.
 */
final readonly class UserId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidUserId
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidUserId::blank();
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
