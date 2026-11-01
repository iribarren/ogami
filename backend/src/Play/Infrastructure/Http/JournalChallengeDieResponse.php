<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One challenge die of a recorded check.
 */
#[OA\Schema(required: ['face', 'beaten'])]
final readonly class JournalChallengeDieResponse
{
    private function __construct(
        #[OA\Property(description: 'The face the die landed on.', example: 7)]
        public int $face,
        #[OA\Property(description: 'Whether the score beat the die: its face is below the score. A tie goes to the die.')]
        public bool $beaten,
    ) {
    }

    /**
     * @param array{face: int, beaten: bool} $die
     */
    public static function of(array $die): self
    {
        return new self($die['face'], $die['beaten']);
    }
}
