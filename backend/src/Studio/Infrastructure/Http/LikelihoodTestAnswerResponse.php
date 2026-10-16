<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Randomness\Application\OracleTableResultView;
use App\Randomness\Infrastructure\Http\LikelihoodAnswerResponse;
use App\Randomness\Infrastructure\Http\OracleTableResultResponse;
use App\Studio\Application\LikelihoodTestView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The outcome of a test answer: Randomness's answer and, when it signalled a random event, the
 * roll on the oracle's random event table.
 */
#[OA\Schema(required: ['answer', 'randomEvent'])]
final readonly class LikelihoodTestAnswerResponse
{
    private function __construct(
        #[OA\Property(ref: new Model(type: LikelihoodAnswerResponse::class), description: 'Its "randomEvent" is the key of the event table when the roll signalled a random event.')]
        public LikelihoodAnswerResponse $answer,
        #[OA\Property(ref: new Model(type: OracleTableResultResponse::class), description: 'The roll on the random event table (nested tables followed); null when the roll signalled no random event.', nullable: true)]
        public ?OracleTableResultResponse $randomEvent,
    ) {
    }

    public static function fromView(LikelihoodTestView $view): self
    {
        return new self(
            LikelihoodAnswerResponse::fromView($view->answer),
            $view->randomEvent instanceof OracleTableResultView ? OracleTableResultResponse::fromView($view->randomEvent) : null,
        );
    }
}
