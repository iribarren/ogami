<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Identifies a GameSystem draft. A UUID, the type of the id column, kept in lower case.
 */
final readonly class GameSystemId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidGameSystemId when the value is not a UUID
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value)) {
            throw InvalidGameSystemId::notAUuid();
        }

        return new self(strtolower($value));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
