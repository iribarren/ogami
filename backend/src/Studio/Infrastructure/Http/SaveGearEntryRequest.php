<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/studio/game-systems/{key}/gear/{gear}`.
 * Documents the contract only: the controller reads the fields from the request, and the
 * publisher's rules validate the values. The entry's key is the path's; a "key" in the body is refused.
 */
#[OA\Schema(required: ['expectedVersion', 'name', 'cost'])]
final readonly class SaveGearEntryRequest
{
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(description: 'What the entry is called (1 to 60 characters); unique in the catalog, trimmed and ignoring case.', example: 'Used pistol')]
        public string $name,
        #[OA\Property(description: 'What it costs in the sheet\'s currency (0 to 1,000,000).', example: 50)]
        public int $cost,
        #[OA\Property(description: 'The inventory slots one unit takes (0 to 100); 1 when left out.', example: 1, nullable: true)]
        public ?int $slots,
        #[OA\Property(description: 'The category inventories and shops select by (1 to 40 characters); none when left out.', example: 'Weapons', nullable: true)]
        public ?string $category,
        #[OA\Property(description: 'A line of flavor (up to 500 characters); none when left out.', example: 'Cheap and loud.', nullable: true)]
        public ?string $description,
    ) {
    }
}
