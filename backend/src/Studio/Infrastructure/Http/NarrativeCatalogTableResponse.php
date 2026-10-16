<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * An oracle table the narrative editors may pick, with the keys of its entries.
 */
#[OA\Schema(required: ['key', 'name', 'entries'])]
final readonly class NarrativeCatalogTableResponse
{
    /**
     * @param list<string> $entries
     */
    public function __construct(
        #[OA\Property(example: 'complications')]
        public string $key,
        #[OA\Property(example: 'Complications')]
        public string $name,
        #[OA\Property(description: 'The keys of its entries, which a table step can branch on.', type: 'array', items: new OA\Items(type: 'string'), example: ['patrol', 'lucky-break'])]
        public array $entries,
    ) {
    }
}
