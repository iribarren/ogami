<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\SheetPointBudgetView;
use OpenApi\Attributes as OA;

/**
 * A point budget of a draft's sheet, with its key (ADR 0043): Characters spend up to the total
 * across the listed number fields when they are created.
 */
#[OA\Schema(required: ['key', 'label', 'total', 'fields', 'min', 'max'])]
final readonly class SheetPointBudgetEntryResponse
{
    /**
     * @param list<string> $fields
     */
    private function __construct(
        #[OA\Property(description: 'The budget\'s key, which addresses it in the point budget routes; "points" for a legacy single budget.', example: 'skills')]
        public string $key,
        #[OA\Property(description: 'What the points are called; null for a legacy budget saved without a name.', example: 'Skill points', nullable: true)]
        public ?string $label,
        #[OA\Property(description: 'The most a Character spends.', example: 8)]
        public int $total,
        #[OA\Property(description: 'The keys of the number fields the budget covers.', type: 'array', items: new OA\Items(type: 'string'), example: ['lore', 'sneak'])]
        public array $fields,
        #[OA\Property(description: 'The least a covered field takes at creation; null when only the field\'s own minimum applies.', example: 0, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'The most a covered field takes at creation; null when only the field\'s own maximum applies.', example: 3, nullable: true)]
        public ?int $max,
    ) {
    }

    public static function fromView(SheetPointBudgetView $view): self
    {
        return new self($view->key, $view->label, $view->total, $view->fields, $view->min, $view->max);
    }
}
