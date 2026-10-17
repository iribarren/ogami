<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckTrackerView;
use OpenApi\Attributes as OA;

/**
 * A tracker of the draft a check formula or band bound may read, with the value a test roll starts it at.
 */
#[OA\Schema(required: ['key', 'label', 'initial'])]
final readonly class CheckTrackerResponse
{
    private function __construct(
        #[OA\Property(description: 'The tracker\'s key, the one after "tracker:" in a formula.', example: 'alarm')]
        public string $key,
        #[OA\Property(description: 'The tracker\'s name.', example: 'Alarm')]
        public string $label,
        #[OA\Property(description: 'A counter\'s initial value, 0 for a clock; null when the draft states none.', example: 0, nullable: true)]
        public ?int $initial,
    ) {
    }

    public static function fromView(CheckTrackerView $view): self
    {
        return new self($view->key, $view->label, $view->initial);
    }
}
