<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The difficulty a check lets the player set.
 */
#[OA\Schema(required: ['default', 'min', 'max'])]
final readonly class NarrativeCatalogDifficultyResponse
{
    public function __construct(
        #[OA\Property(example: 13)]
        public int $default,
        #[OA\Property(description: 'Lowest difficulty the player may set; null when unbounded.', example: 9, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'Highest difficulty the player may set; null when unbounded.', example: 29, nullable: true)]
        public ?int $max,
    ) {
    }
}
