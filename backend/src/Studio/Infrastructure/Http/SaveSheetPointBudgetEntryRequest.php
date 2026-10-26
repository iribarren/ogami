<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/studio/game-systems/{key}/sheet/point-budgets/{budget}`.
 * Documents the contract only: the controller reads the fields from the request, and the
 * publisher's rules validate the values. The budget's key is the path's.
 */
#[OA\Schema(required: ['expectedVersion', 'label', 'total', 'fields'])]
final readonly class SaveSheetPointBudgetEntryRequest
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(description: 'What the points are called (1 to 60 characters, not blank).', example: 'Skill points')]
        public string $label,
        #[OA\Property(description: 'The most a Character spends (1 to 1,000,000). It must cover the lowest values the fields can take.', example: 8)]
        public int $total,
        #[OA\Property(description: 'The keys of the number fields the budget covers: at least one, distinct, and in no other budget.', type: 'array', items: new OA\Items(type: 'string'), example: ['lore', 'sneak'], minItems: 1)]
        public array $fields,
        #[OA\Property(description: 'The least a covered field takes at creation, inside the field\'s own bounds; only the field\'s minimum when left out.', example: 0, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'The most a covered field takes at creation, inside the field\'s own bounds and at least min; only the field\'s maximum when left out.', example: 3, nullable: true)]
        public ?int $max,
    ) {
    }
}
