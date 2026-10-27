<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckTestPoolDieView;
use OpenApi\Attributes as OA;

/**
 * A die of a test-rolled pool.
 */
#[OA\Schema(required: ['face', 'success', 'rerolled', 'group'])]
final readonly class CheckTestRollPoolDieResponse
{
    private function __construct(
        #[OA\Property(description: 'The face the die shows.', example: 5)]
        public int $face,
        #[OA\Property(description: 'Whether the face reaches the pool\'s success threshold.', example: true)]
        public bool $success,
        #[OA\Property(description: 'Whether a push re-rolled this die; always false before a push.', example: false)]
        public bool $rerolled,
        #[OA\Property(description: 'The group of dice the die belongs to: "pool" for a regular die, "hunger" for a Hunger die, which a push never re-rolls (ADR 0044).', example: 'pool', enum: ['pool', 'hunger'])]
        public string $group,
    ) {
    }

    public static function fromView(CheckTestPoolDieView $view): self
    {
        return new self($view->face, $view->success, $view->rerolled, $view->group);
    }
}
