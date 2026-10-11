<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * Identifies an NPC. Opaque string (a UUID v7 in production).
 */
final readonly class NpcId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidNpc
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidNpc::because('An NPC id must not be blank.');
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
