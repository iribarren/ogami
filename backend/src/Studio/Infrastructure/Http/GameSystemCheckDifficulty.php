<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The difficulty a check declares. Documents the contract only.
 */
#[OA\Schema(description: 'Integers between -1,000 and 1,000 with min <= default <= max.', required: ['label', 'default'])]
final readonly class GameSystemCheckDifficulty
{
    public function __construct(
        #[OA\Property(example: 'Difficulty Value', maxLength: 60)]
        public string $label,
        #[OA\Property(example: 13)]
        public int $default,
        #[OA\Property(description: 'The lowest difficulty a step may set.', example: 9)]
        public ?int $min,
        #[OA\Property(description: 'The highest difficulty a step may set.', example: 29)]
        public ?int $max,
    ) {
    }
}
