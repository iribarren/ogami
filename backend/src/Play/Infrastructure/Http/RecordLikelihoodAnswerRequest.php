<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\LikelihoodContent;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/journal/likelihood-oracles/{oracleKey}`.
 * Documents the contract only: the controller reads the fields from the request.
 */
#[OA\Schema(required: ['likelihood'])]
final readonly class RecordLikelihoodAnswerRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of one of the oracle\'s likelihood levels.', example: 'likely')]
        public string $likelihood,
        #[OA\Property(description: 'Within the oracle\'s chaos range; omitted or null for its neutral factor. Must be omitted or null when the oracle has no chaos.', example: 5)]
        public ?int $chaosFactor = null,
        #[OA\Property(description: 'Trimmed; omitted, null or blank for no question.', example: 'Is the door locked?', maxLength: LikelihoodContent::MAX_QUESTION_LENGTH)]
        public ?string $question = null,
    ) {
    }
}
