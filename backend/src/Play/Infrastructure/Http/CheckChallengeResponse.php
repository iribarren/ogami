<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CheckChallengeView;
use OpenApi\Attributes as OA;

/**
 * The challenge dice a check compares its score against: the score is the action die (if the check
 * has one) plus the modifier, and the total is how many challenge dice it beats.
 */
#[OA\Schema(required: ['count', 'sides', 'scoreMax', 'momentumLabel', 'momentum'])]
final readonly class CheckChallengeResponse
{
    private function __construct(
        #[OA\Property(description: 'How many challenge dice are rolled.', example: 2)]
        public int $count,
        #[OA\Property(description: 'The faces of each challenge die.', example: 10)]
        public int $sides,
        #[OA\Property(description: 'The highest score; null when the score is not capped.', example: 10, nullable: true)]
        public ?int $scoreMax,
        #[OA\Property(description: 'The label of the momentum field whose negative value can cancel the action die; null when the check names none.', example: 'Momentum', nullable: true)]
        public ?string $momentumLabel,
        #[OA\Property(description: 'The key of the momentum field in a Character\'s values: what the roll reads, and what a burn spends; null when the check names none.', example: 'momentum', nullable: true)]
        public ?string $momentum,
    ) {
    }

    public static function fromView(CheckChallengeView $view): self
    {
        return new self($view->count, $view->sides, $view->scoreMax, $view->momentumLabel, $view->momentum);
    }
}
