<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Identifies an Invitation. Opaque string (a UUID in production); generated
 * through the Application's InvitationIdGenerator port.
 */
final readonly class InvitationId
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
            throw new \InvalidArgumentException('An invitation id cannot be blank.');
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
