<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Sheet\PointBudget;
use OpenApi\Attributes as OA;

/**
 * A point budget of the sheet with its key (ADR 0043): at most `total` over `fields`, each inside
 * its own bounds and the budget's creation-only `min` and `max`. A legacy single budget is the key
 * "points".
 */
#[OA\Schema(required: ['key', 'label', 'total', 'fields', 'min', 'max'])]
final readonly class PointBudgetEntryResponse
{
    /**
     * @param list<string> $fields
     */
    private function __construct(
        #[OA\Property(description: 'The budget\'s key in the sheet; "points" for a legacy single budget.', example: 'skills')]
        public string $key,
        #[OA\Property(description: 'What to call the points; null to call them "Points".', example: 'Skill points', nullable: true)]
        public ?string $label,
        #[OA\Property(description: 'The most points a player spends.', example: 8)]
        public int $total,
        #[OA\Property(description: 'The keys of the number fields the points are spent on.', type: 'array', items: new OA\Items(type: 'string'), example: ['lore', 'sneak'])]
        public array $fields,
        #[OA\Property(description: 'The lowest value a budget field takes when a Character is created; null when the budget adds no lower bound to the field\'s own.', example: 0, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'The highest value a budget field takes when a Character is created; null when the budget adds no upper bound to the field\'s own.', example: 3, nullable: true)]
        public ?int $max,
    ) {
    }

    public static function fromBudget(PointBudget $budget): self
    {
        return new self($budget->key, $budget->label, $budget->total, $budget->fields, $budget->min, $budget->max);
    }
}
