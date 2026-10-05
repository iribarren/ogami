<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * Identifies a GameSystem release. Opaque string (a UUID in production).
 */
final readonly class ReleaseId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidReleaseId
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidReleaseId::blank();
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
