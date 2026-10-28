<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One line of the "sell" list of a shop step's answer (ADR 0045). Documents the contract only: the
 * controller reads the lines from the request.
 */
#[OA\Schema(required: ['field', 'gear', 'quantity'])]
final readonly class ShopSellLineRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of the Character\'s inventory field the stack is sold from.', example: 'pack')]
        public string $field,
        #[OA\Property(description: 'The key of the catalog gear of the stack.', example: 'used-pistol')]
        public string $gear,
        #[OA\Property(description: 'How many units, 1 to 9999, at most what the stack holds.', example: 1, minimum: 1, maximum: 9999)]
        public int $quantity,
    ) {
    }
}
