<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * What the Flow decided on its own: the label of the outcome band it took.
 */
#[OA\Schema(required: ['kind', 'label'])]
final readonly class FlowNoteBandResponse
{
    public function __construct(
        #[OA\Property(enum: ['band'])]
        public string $kind,
        #[OA\Property(description: 'The label of the band.', example: 'Strong hit')]
        public string $label,
    ) {
    }
}
