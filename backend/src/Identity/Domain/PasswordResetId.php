<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Identifies a PasswordReset. Opaque string (a UUID in production); generated
 * through the Application's PasswordResetIdGenerator port.
 */
final readonly class PasswordResetId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the value is blank
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('A password reset id cannot be blank.');
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
