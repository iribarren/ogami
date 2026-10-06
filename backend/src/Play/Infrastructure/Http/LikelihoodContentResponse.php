<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\LikelihoodContent;
use OpenApi\Attributes as OA;

/**
 * A likelihood oracle of the campaign's pinned release asked a yes/no question, in the same shape
 * as the answer of `POST /api/likelihood-answers` plus the oracle and the question.
 */
#[OA\Schema(required: ['kind', 'oracleKey', 'oracleName', 'question', 'answer', 'roll', 'sides', 'effectiveTarget', 'likelihood', 'likelihoodLabel', 'chaosFactor'])]
final readonly class LikelihoodContentResponse
{
    private function __construct(
        #[OA\Property(enum: [LikelihoodContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The likelihood oracle asked.', example: 'fate')]
        public string $oracleKey,
        #[OA\Property(example: 'Fate question')]
        public string $oracleName,
        #[OA\Property(description: 'The question asked; null when none was given.', example: 'Is the door locked?', maxLength: LikelihoodContent::MAX_QUESTION_LENGTH)]
        public ?string $question,
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

    public static function of(LikelihoodContent $content): self
    {
        $data = $content->toArray();

        return new self(
            LikelihoodContent::KIND,
            $data['oracleKey'],
            $data['oracleName'],
            $data['question'],
            $data['answer'],
            $data['roll'],
            $data['sides'],
            $data['effectiveTarget'],
            $data['likelihood'],
            $data['likelihoodLabel'],
            $data['chaosFactor'],
        );
    }
}
