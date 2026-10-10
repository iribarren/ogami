<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\ChoiceContent;
use OpenApi\Attributes as OA;

/**
 * The option the player chose at a choice step of a Flow.
 */
#[OA\Schema(required: ['kind', 'question', 'optionKey', 'label'])]
final readonly class ChoiceContentResponse
{
    private function __construct(
        #[OA\Property(enum: [ChoiceContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The question the step asked.', example: 'Did you gain an edge?', maxLength: ChoiceContent::MAX_QUESTION_LENGTH)]
        public string $question,
        #[OA\Property(description: 'The key of the option chosen.', example: 'yes')]
        public string $optionKey,
        #[OA\Property(description: 'The label of the option chosen.', example: 'Yes', maxLength: ChoiceContent::MAX_LABEL_LENGTH)]
        public string $label,
    ) {
    }

    public static function of(ChoiceContent $content): self
    {
        return new self(ChoiceContent::KIND, $content->question(), $content->optionKey(), $content->label());
    }
}
