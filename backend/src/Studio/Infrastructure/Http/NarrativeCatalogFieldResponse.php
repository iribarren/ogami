<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * A number or track field of the sheet, which a sheet effect may change.
 */
#[OA\Schema(required: ['key', 'label'])]
final readonly class NarrativeCatalogFieldResponse
{
    public function __construct(
        #[OA\Property(example: 'hands')]
        public string $key,
        #[OA\Property(example: 'Hands')]
        public string $label,
    ) {
    }
}
