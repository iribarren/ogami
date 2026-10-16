<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * A likelihood oracle the narrative editors may pick, with the keys of its levels.
 */
#[OA\Schema(required: ['key', 'name', 'levels'])]
final readonly class NarrativeCatalogLikelihoodResponse
{
    /**
     * @param list<string> $levels
     */
    public function __construct(
        #[OA\Property(example: 'fate')]
        public string $key,
        #[OA\Property(example: 'Fate question')]
        public string $name,
        #[OA\Property(description: 'The keys of its levels, which an oracle step may ask at.', type: 'array', items: new OA\Items(type: 'string'), example: ['unlikely', 'even', 'likely'])]
        public array $levels,
    ) {
    }
}
