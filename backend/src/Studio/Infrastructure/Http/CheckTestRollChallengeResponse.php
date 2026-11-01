<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckTestChallengeView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The challenge dice of a test-rolled challenge check (ADR 0049): the score, each die against it and,
 * after a burn, the burn.
 */
#[OA\Schema(required: ['actionDie', 'sides', 'scoreMax', 'score', 'momentum', 'momentumField', 'cancelled', 'dice', 'burn'])]
final readonly class CheckTestRollChallengeResponse
{
    /**
     * @param list<CheckTestRollChallengeDieResponse> $dice
     */
    private function __construct(
        #[OA\Property(description: 'The action die as rolled: the total of the check\'s dice; null on a progress roll, which rolls none. A cancelled die is still shown.', example: 4, nullable: true)]
        public ?int $actionDie,
        #[OA\Property(description: 'The faces of each challenge die.', example: 10)]
        public int $sides,
        #[OA\Property(description: 'The highest the score can be; null for no cap.', example: 10, nullable: true)]
        public ?int $scoreMax,
        #[OA\Property(description: 'The score the challenge dice were compared against: the action die (0 when cancelled), the modifier and the situational modifier, capped at the score cap; after a burn, the burned score.', example: 6)]
        public int $score,
        #[OA\Property(description: 'The value of the check\'s momentum field for the samples; null when the check names none.', example: 2, nullable: true)]
        public ?int $momentum,
        #[OA\Property(description: 'The key of the momentum field; null when the check names none.', example: 'momentum', nullable: true)]
        public ?string $momentumField,
        #[OA\Property(description: 'Whether negative momentum equal to the action die cancelled it, so it counted 0.', example: false)]
        public bool $cancelled,
        #[OA\Property(
            description: 'Every challenge die in roll order, with whether the score beat it.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: CheckTestRollChallengeDieResponse::class)),
        )]
        public array $dice,
        #[OA\Property(ref: new Model(type: CheckTestRollBurnResponse::class), description: 'Only on a burned roll: the momentum burned and the score it made; null before a burn.', nullable: true)]
        public ?CheckTestRollBurnResponse $burn,
    ) {
    }

    public static function fromView(CheckTestChallengeView $view): self
    {
        return new self(
            $view->actionDie,
            $view->sides,
            $view->scoreMax,
            $view->score,
            $view->momentum,
            $view->momentumField,
            $view->cancelled,
            array_map(CheckTestRollChallengeDieResponse::fromView(...), $view->dice),
            null === $view->burnMomentum || null === $view->burnScore ? null : CheckTestRollBurnResponse::of($view->burnMomentum, $view->burnScore),
        );
    }
}
