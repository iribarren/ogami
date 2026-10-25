<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One die of a recorded pool roll.
 */
#[OA\Schema(required: ['group', 'face', 'success'])]
final readonly class JournalPoolDieResponse
{
    private function __construct(
        #[OA\Property(description: 'The group of dice the die belongs to; "pool" for the dice of a pool.', example: 'pool')]
        public string $group,
        #[OA\Property(description: 'The face the die landed on.', example: 5)]
        public int $face,
        #[OA\Property(description: 'Whether the die succeeded: its face reached the success threshold.')]
        public bool $success,
    ) {
    }

    /**
     * @param array{group: string, face: int, success: bool} $die
     */
    public static function of(array $die): self
    {
        return new self($die['group'], $die['face'], $die['success']);
    }
}
