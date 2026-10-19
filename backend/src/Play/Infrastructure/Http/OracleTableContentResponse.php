<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\OracleTableContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A roll on an oracle table of the campaign's pinned release, with every resolution step.
 */
#[OA\Schema(required: ['kind', 'oracleKey', 'oracleName', 'forEntry', 'steps'])]
final readonly class OracleTableContentResponse
{
    /**
     * @param list<JournalOracleTableStepResponse> $steps
     */
    private function __construct(
        #[OA\Property(enum: [OracleTableContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The oracle table asked.', example: 'weather')]
        public string $oracleKey,
        #[OA\Property(example: 'Weather')]
        public string $oracleName,
        #[OA\Property(description: 'The id of the likelihood entry (the fate question) whose random event this roll answers; null for any other roll.', example: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001')]
        public ?string $forEntry,
        #[OA\Property(
            description: 'Root table first, then each nested table rolled.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalOracleTableStepResponse::class)),
            minItems: 1,
        )]
        public array $steps,
    ) {
    }

    public static function of(OracleTableContent $content): self
    {
        return new self(
            OracleTableContent::KIND,
            $content->oracleKey(),
            $content->oracleName(),
            $content->forEntry(),
            array_map(JournalOracleTableStepResponse::of(...), $content->steps()),
        );
    }
}
