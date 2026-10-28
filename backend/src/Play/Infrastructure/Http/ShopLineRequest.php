<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One line of the "buy" list of a shop step's answer (ADR 0045). Documents the contract only: the
 * controller reads the lines from the request.
 */
#[OA\Schema(required: ['gear', 'quantity'])]
final readonly class ShopLineRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of the gear on sale to buy.', example: 'used-pistol')]
        public string $gear,
        #[OA\Property(description: 'How many units, 1 to 9999.', example: 2, maximum: 9999, minimum: 1)]
        public int $quantity,
    ) {
    }
}
