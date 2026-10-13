<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

/**
 * Identifies a Character. Opaque string (a UUID v7 in production).
 */
final readonly class CharacterId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidCharacter
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidCharacter::because('A Character id must not be blank.');
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
