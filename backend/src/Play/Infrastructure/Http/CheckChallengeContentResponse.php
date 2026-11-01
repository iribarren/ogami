<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\CheckChallengeContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The challenge part of a recorded check: the score, its cap, the momentum that was read, and each
 * challenge die with whether the score beat it. A match is every die showing the same face.
 */
#[OA\Schema(required: ['sides', 'scoreMax', 'score', 'momentum', 'cancelled', 'dice'])]
final readonly class CheckChallengeContentResponse
{
    /**
     * @param list<JournalChallengeDieResponse> $dice
     */
    private function __construct(
        #[OA\Property(description: 'The faces of each challenge die.', example: 10)]
        public int $sides,
        #[OA\Property(description: 'The highest score the check allowed; null when it was not capped.', example: 10, nullable: true)]
        public ?int $scoreMax,
        #[OA\Property(description: 'The score the challenge dice were compared against, after the cap.', example: 6)]
        public int $score,
        #[OA\Property(description: 'The value of the momentum field when the roll was made; null when the check names none.', example: -2, nullable: true)]
        public ?int $momentum,
        #[OA\Property(description: 'Whether a negative momentum equal to the action die made it count 0.')]
        public bool $cancelled,
        #[OA\Property(
            description: 'Every challenge die, in roll order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalChallengeDieResponse::class)),
        )]
        public array $dice,
    ) {
    }

    public static function of(CheckChallengeContent $challenge): self
    {
        return new self($challenge->sides, $challenge->scoreMax, $challenge->score, $challenge->momentum, $challenge->cancelled, array_map(JournalChallengeDieResponse::of(...), $challenge->dice));
    }
}
