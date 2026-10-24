<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Sheet\PointBudget;
use OpenApi\Attributes as OA;

/**
 * The points a player spends on number fields when a Character is created (ADR 0040): at most
 * `total` over `fields`, each inside its own bounds and the budget's creation-only `min` and `max`.
 */
#[OA\Schema(required: ['label', 'total', 'fields', 'min', 'max'])]
final readonly class PointBudgetResponse
{
    /**
     * @param list<string> $fields
     */
    private function __construct(
        #[OA\Property(description: 'What to call the points; null to call them "Points".', example: 'Characteristic points', nullable: true)]
        public ?string $label,
        #[OA\Property(description: 'The most points a player spends.', example: 460)]
        public int $total,
        #[OA\Property(description: 'The keys of the number fields the points are spent on.', type: 'array', items: new OA\Items(type: 'string'), example: ['str', 'con'])]
        public array $fields,
        #[OA\Property(description: 'The lowest value a budget field takes when a Character is created; null when the budget adds no lower bound to the field\'s own.', example: 15, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'The highest value a budget field takes when a Character is created; null when the budget adds no upper bound to the field\'s own.', example: 90, nullable: true)]
        public ?int $max,
    ) {
    }

    public static function fromBudget(?PointBudget $budget): ?self
    {
        return $budget instanceof PointBudget ? new self($budget->label, $budget->total, $budget->fields, $budget->min, $budget->max) : null;
    }
}
