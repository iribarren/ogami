<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One line of a shop transaction in the journal (ADR 0045): units of a catalog entry and the price of one.
 */
#[OA\Schema(required: ['field', 'gear', 'name', 'quantity', 'price'])]
final readonly class ShopLineResponse
{
    private function __construct(
        #[OA\Property(description: 'The inventory field a sold stack left; null for a purchase.', example: 'gear', nullable: true)]
        public ?string $field,
        #[OA\Property(description: 'The catalog key.', example: 'kibble')]
        public string $gear,
        #[OA\Property(description: 'The name of the gear when the step completed.', example: 'Kibble by the kilo')]
        public string $name,
        #[OA\Property(example: 3)]
        public int $quantity,
        #[OA\Property(description: 'The price of one unit: its cost for a purchase, the shop\'s sale price for a sale.', example: 10)]
        public int $price,
    ) {
    }

    public static function of(?string $field, string $gear, string $name, int $quantity, int $price): self
    {
        return new self($field, $gear, $name, $quantity, $price);
    }
}
