<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The dice a sheet Effect rolled.
 */
#[OA\Schema(required: ['expression', 'total'])]
final readonly class SheetChangeDiceResponse
{
    public function __construct(
        #[OA\Property(description: 'The normalized notation of the dice rolled.', example: '1d6')]
        public string $expression,
        #[OA\Property(example: 4)]
        public int $total,
    ) {
    }
}
