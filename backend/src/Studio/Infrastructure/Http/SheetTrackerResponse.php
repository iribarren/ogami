<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\SheetTrackerView;
use OpenApi\Attributes as OA;

/**
 * A tracker of the draft as the formula editor lists it: a derived field may read it as {tracker:key}.
 */
#[OA\Schema(required: ['key', 'label', 'max'])]
final readonly class SheetTrackerResponse
{
    private function __construct(
        #[OA\Property(description: 'The tracker\'s key, the one after "tracker:" in a formula.', example: 'alarm')]
        public string $key,
        #[OA\Property(description: 'The tracker\'s name.', example: 'Alarm')]
        public string $label,
        #[OA\Property(description: 'A counter\'s max or a clock\'s segments; null when the tracker has none.', example: 6, nullable: true)]
        public ?int $max,
    ) {
    }

    public static function fromView(SheetTrackerView $view): self
    {
        return new self($view->key, $view->label, $view->max);
    }
}
