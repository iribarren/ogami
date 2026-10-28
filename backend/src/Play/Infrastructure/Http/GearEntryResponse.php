<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\GearEntry;
use OpenApi\Attributes as OA;

/**
 * One entry of the gear catalog of a campaign's pinned release (ADR 0045).
 */
#[OA\Schema(required: ['key', 'name', 'cost', 'slots', 'category', 'description'])]
final readonly class GearEntryResponse
{
    private function __construct(
        #[OA\Property(description: 'The key a catalog stack of a Character names the gear by.', example: 'used-pistol')]
        public string $key,
        #[OA\Property(example: 'Used pistol')]
        public string $name,
        #[OA\Property(description: 'The price of one unit in the currency of the sheet.', example: 50)]
        public int $cost,
        #[OA\Property(description: 'The inventory slots one unit takes (1 unless the release says otherwise).', example: 1)]
        public int $slots,
        #[OA\Property(description: 'The label inventory fields match exactly; null when the entry has none.', example: 'Gear', nullable: true)]
        public ?string $category,
        #[OA\Property(nullable: true)]
        public ?string $description,
    ) {
    }

    public static function fromEntry(GearEntry $entry): self
    {
        return new self($entry->key, $entry->name, $entry->cost, $entry->slots, $entry->category, $entry->description);
    }
}
