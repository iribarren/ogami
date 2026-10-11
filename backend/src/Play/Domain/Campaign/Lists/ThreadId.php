<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * Identifies a Thread. Opaque string (a UUID v7 in production).
 */
final readonly class ThreadId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidThread
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidThread::because('A Thread id must not be blank.');
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
