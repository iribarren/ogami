<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\SheetGearEntryView;
use OpenApi\Attributes as OA;

/**
 * A gear entry of a draft's catalog (ADR 0045), with its key, which addresses it in the gear routes.
 */
#[OA\Schema(required: ['key', 'name', 'cost', 'slots', 'category', 'description'])]
final readonly class SheetGearEntryResponse
{
    private function __construct(
        #[OA\Property(description: 'The entry\'s key, which addresses it in the gear routes and which shop steps and Characters name it by.', example: 'used-pistol')]
        public string $key,
        #[OA\Property(description: 'What the entry is called.', example: 'Used pistol')]
        public string $name,
        #[OA\Property(description: 'What it costs in the sheet\'s currency.', example: 50)]
        public int $cost,
        #[OA\Property(description: 'The inventory slots one unit takes; 1 when the entry stores none.', example: 1)]
        public int $slots,
        #[OA\Property(description: 'The category inventories and shops select by; null for none.', example: 'Weapons', nullable: true)]
        public ?string $category,
        #[OA\Property(description: 'A line of flavor; null for none.', example: 'Cheap and loud.', nullable: true)]
        public ?string $description,
    ) {
    }

    public static function fromView(SheetGearEntryView $view): self
    {
        return new self($view->key, $view->name, $view->cost, $view->slots, $view->category, $view->description);
    }
}
