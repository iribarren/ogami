<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One die of a recorded roll.
 */
#[OA\Schema(required: ['value', 'kept'])]
final readonly class JournalRolledDieResponse
{
    private function __construct(
        #[OA\Property(description: 'The face the die landed on.', example: 4)]
        public int $value,
        #[OA\Property(description: 'Whether the die counts towards the total; false when a selector dropped it.')]
        public bool $kept,
    ) {
    }

    public static function of(int $value, bool $kept): self
    {
        return new self($value, $kept);
    }
}
