<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\SheetPointBudgetView;
use OpenApi\Attributes as OA;

/**
 * The point budget of a draft's sheet: Characters spend up to the total across the listed number
 * fields when they are created.
 */
#[OA\Schema(required: ['label', 'total', 'fields', 'min', 'max'])]
final readonly class SheetPointBudgetResponse
{
    /**
     * @param list<string> $fields
     */
    private function __construct(
        #[OA\Property(description: 'What the points are called; null when the budget has no name.', example: 'Characteristic points', nullable: true)]
        public ?string $label,
        #[OA\Property(description: 'The most a Character spends.', example: 460)]
        public int $total,
        #[OA\Property(description: 'The keys of the number fields the budget covers.', type: 'array', items: new OA\Items(type: 'string'), example: ['str', 'con'])]
        public array $fields,
        #[OA\Property(description: 'The least a covered field takes at creation; null when only the field\'s own minimum applies.', example: 15, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'The most a covered field takes at creation; null when only the field\'s own maximum applies.', example: 90, nullable: true)]
        public ?int $max,
    ) {
    }

    public static function fromView(SheetPointBudgetView $view): self
    {
        return new self($view->label, $view->total, $view->fields, $view->min, $view->max);
    }
}
