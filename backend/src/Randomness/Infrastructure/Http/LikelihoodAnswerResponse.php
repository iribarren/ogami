<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\LikelihoodAnswerView;
use OpenApi\Attributes as OA;

/**
 * JSON body of a successful `POST /api/likelihood-answers`.
 */
#[OA\Schema(required: ['answer', 'roll', 'sides', 'effectiveTarget', 'likelihood', 'likelihoodLabel', 'chaosFactor'])]
final readonly class LikelihoodAnswerResponse
{
    private function __construct(
        #[OA\Property(description: 'Yes when the roll is at most the effective target, no otherwise; exceptional within the oracle\'s exceptional bands.', example: 'yes', enum: ['exceptional_yes', 'yes', 'no', 'exceptional_no'])]
        public string $answer,
        #[OA\Property(description: 'The roll on 1d<sides>.', example: 42)]
        public int $roll,
        #[OA\Property(example: 100)]
        public int $sides,
        #[OA\Property(description: 'The level\'s target shifted by the chaos factor and clamped to 0…sides.', example: 65)]
        public int $effectiveTarget,
        #[OA\Property(description: 'The key of the likelihood level asked with.', example: 'likely')]
        public string $likelihood,
        #[OA\Property(example: 'Likely')]
        public string $likelihoodLabel,
        #[OA\Property(description: 'The chaos factor used (the neutral one when none was given); null when the oracle has no chaos.', example: 5)]
        public ?int $chaosFactor,
    ) {
    }

    public static function fromView(LikelihoodAnswerView $view): self
    {
        return new self(
            $view->answer,
            $view->roll,
            $view->sides,
            $view->effectiveTarget,
            $view->likelihood,
            $view->likelihoodLabel,
            $view->chaosFactor,
        );
    }
}
