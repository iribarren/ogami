<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One die of a pool roll after its push.
 */
#[OA\Schema(required: ['group', 'face', 'success', 'rerolled'])]
final readonly class JournalPushDieResponse
{
    private function __construct(
        #[OA\Property(description: 'The group of dice the die belongs to: "pool" for a regular die, "hunger" for a Hunger die, which a push never re-rolls.', example: 'pool', enum: ['pool', 'hunger'])]
        public string $group,
        #[OA\Property(description: 'The face the die shows after the push.', example: 5)]
        public int $face,
        #[OA\Property(description: 'Whether the die succeeded: its face reached the success threshold.')]
        public bool $success,
        #[OA\Property(description: 'Whether the push re-rolled the die: a regular die that failed before the push, within the cap of the push.')]
        public bool $rerolled,
    ) {
    }

    /**
     * @param array{group: string, face: int, success: bool, rerolled: bool} $die
     */
    public static function of(array $die): self
    {
        return new self($die['group'], $die['face'], $die['success'], $die['rerolled']);
    }
}
