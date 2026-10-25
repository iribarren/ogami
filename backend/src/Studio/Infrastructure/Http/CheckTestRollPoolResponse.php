<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckTestPoolView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The pool of a test-rolled pool check (ADR 0041): its size, dice and successes.
 */
#[OA\Schema(required: ['size', 'sides', 'successAt', 'count', 'countFormula', 'dice', 'successes'])]
final readonly class CheckTestRollPoolResponse
{
    /**
     * @param list<CheckTestRollPoolDieResponse> $dice
     */
    private function __construct(
        #[OA\Property(description: 'The dice rolled: the count plus the situational modifier, kept between 1 and 100.', example: 5)]
        public int $size,
        #[OA\Property(description: 'The faces of each die.', example: 6)]
        public int $sides,
        #[OA\Property(description: 'A die succeeds on this face or higher.', example: 5)]
        public int $successAt,
        #[OA\Property(description: 'The number of dice the check asked for, before the situational modifier: its number, or what its formula gave for the samples.', example: 4)]
        public int $count,
        #[OA\Property(description: 'The formula that gave the count; null when the check gives a number.', example: '{agility} + {stealth}', nullable: true)]
        public ?string $countFormula,
        #[OA\Property(
            description: 'Every die, in roll order. After a push, the dice that failed are re-rolled in place.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: CheckTestRollPoolDieResponse::class)),
        )]
        public array $dice,
        #[OA\Property(description: 'How many dice succeeded; the total of the check.', example: 2)]
        public int $successes,
    ) {
    }

    public static function fromView(CheckTestPoolView $view): self
    {
        return new self(
            $view->size,
            $view->sides,
            $view->successAt,
            $view->count,
            $view->countFormula,
            array_map(CheckTestRollPoolDieResponse::fromView(...), $view->dice),
            $view->successes,
        );
    }
}
