<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\RolledDieView;
use OpenApi\Attributes as OA;

/**
 * One die of a roll.
 */
#[OA\Schema(required: ['value', 'kept'])]
final readonly class RolledDieResponse
{
    private function __construct(
        #[OA\Property(description: 'The face the die landed on.', example: 5)]
        public int $value,
        #[OA\Property(description: 'Whether the die counts towards the total; false when a selector dropped it.')]
        public bool $kept,
    ) {
    }

    public static function fromView(RolledDieView $view): self
    {
        return new self($view->value, $view->kept);
    }
}
