<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Identifies a GameSystem draft. Opaque string (a UUID in production).
 */
final readonly class GameSystemId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the id is blank
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('A GameSystem id must not be blank.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
