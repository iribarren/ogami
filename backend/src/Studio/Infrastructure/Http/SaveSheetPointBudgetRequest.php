<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/studio/game-systems/{key}/sheet/point-budget`.
 * Documents the contract only: the controller reads the fields from the request, and the
 * publisher's rules validate the values.
 */
#[OA\Schema(required: ['expectedVersion', 'total', 'fields'])]
final readonly class SaveSheetPointBudgetRequest
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(description: 'What the points are called (1 to 60 characters); unnamed when left out.', example: 'Characteristic points', nullable: true)]
        public ?string $label,
        #[OA\Property(description: 'The most a Character spends (1 to 1,000,000). It must cover the lowest values the fields can take.', example: 460)]
        public int $total,
        #[OA\Property(description: 'The keys of the number fields the budget covers: at least one, distinct.', type: 'array', items: new OA\Items(type: 'string'), example: ['str', 'con'], minItems: 1)]
        public array $fields,
        #[OA\Property(description: 'The least a covered field takes at creation, inside the field\'s own bounds; only the field\'s minimum when left out.', example: 15, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'The most a covered field takes at creation, inside the field\'s own bounds and at least min; only the field\'s maximum when left out.', example: 90, nullable: true)]
        public ?int $max,
    ) {
    }
}
