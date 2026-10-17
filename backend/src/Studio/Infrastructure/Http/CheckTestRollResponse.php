<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Randomness\Infrastructure\Http\RollResponse;
use App\Studio\Application\CheckTestRollView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of a successful `POST /api/studio/game-systems/{key}/checks/{check}/test-rolls`: the
 * roll of the saved check for the samples, which is never recorded.
 */
#[OA\Schema(required: ['dice', 'modifier', 'situational', 'difficulty', 'total', 'bands', 'band'])]
final readonly class CheckTestRollResponse
{
    /**
     * @param list<CheckTestRollBandResponse> $bands
     */
    private function __construct(
        #[OA\Property(ref: new Model(type: RollResponse::class), description: 'The check\'s dice as rolled: normalized notation, total and every dice group.')]
        public RollResponse $dice,
        #[OA\Property(description: 'The value of the check\'s modifier formula for the samples; 0 when it has none.', example: 6)]
        public int $modifier,
        #[OA\Property(description: 'The situational modifier that applied.', example: 0)]
        public int $situational,
        #[OA\Property(description: 'The Check difficulty that applied: the one sent, else the declared default; null for a check that declares none.', example: 13, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'The dice, plus the modifier, plus the situational modifier.', example: 13)]
        public int $total,
        #[OA\Property(description: 'Every band in order with its bound for these samples.', type: 'array', items: new OA\Items(ref: new Model(type: CheckTestRollBandResponse::class)))]
        public array $bands,
        #[OA\Property(description: 'The key of the band reached: the first whose bound is at least the total, else the last.', example: 'success')]
        public string $band,
    ) {
    }

    public static function fromView(CheckTestRollView $view): self
    {
        return new self(
            RollResponse::fromView($view->dice),
            $view->modifier,
            $view->situational,
            $view->difficulty,
            $view->total,
            array_map(CheckTestRollBandResponse::fromView(...), $view->bands),
            $view->band,
        );
    }
}
