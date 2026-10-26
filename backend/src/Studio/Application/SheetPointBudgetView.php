<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A point budget of the draft's sheet (ADR 0040, 0043) as stored: what a Character spends over
 * number fields at creation, with the creation-only bounds of those fields. $key is the budget's
 * key in a list, "points" for a legacy single budget.
 */
final readonly class SheetPointBudgetView
{
    /**
     * @param list<string> $fields the keys of the number fields the budget covers
     */
    public function __construct(
        public string $key,
        public ?string $label,
        public int $total,
        public array $fields,
        public ?int $min,
        public ?int $max,
    ) {
    }
}
