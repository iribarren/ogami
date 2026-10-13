<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CheckDifficultyView;
use OpenApi\Attributes as OA;

/**
 * The Check difficulty a check declares, which the player sets when rolling it.
 */
#[OA\Schema(required: ['label', 'default', 'min', 'max'])]
final readonly class CheckDifficultyResponse
{
    private function __construct(
        #[OA\Property(description: 'What the difficulty is called.', example: 'Difficulty Value')]
        public string $label,
        #[OA\Property(description: 'Used when the player sends none.', example: 13)]
        public int $default,
        #[OA\Property(description: 'The lowest difficulty accepted; null when unbounded.', example: 9, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'The highest difficulty accepted; null when unbounded.', example: 29, nullable: true)]
        public ?int $max,
    ) {
    }

    public static function fromView(CheckDifficultyView $view): self
    {
        return new self($view->label, $view->default, $view->min, $view->max);
    }
}
