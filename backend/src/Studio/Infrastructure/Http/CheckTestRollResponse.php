<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Randomness\Application\RollView;
use App\Randomness\Infrastructure\Http\RollResponse;
use App\Studio\Application\CheckTestChallengeView;
use App\Studio\Application\CheckTestPoolView;
use App\Studio\Application\CheckTestRollView;
use App\Studio\Domain\Check\CheckTestSpecialResult;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of a successful `POST /api/studio/game-systems/{key}/checks/{check}/test-rolls` and of
 * its `/push`: the roll of the saved check for the samples, which is never recorded. A check rolls
 * dice or a pool (ADR 0041): the other part is null. A challenge check rolls its dice (the action die) and
 * the challenge dice of ADR 0049; a progress roll has no dice.
 */
#[OA\Schema(required: ['dice', 'pool', 'push', 'challenge', 'rolledSuccesses', 'special', 'modifier', 'situational', 'difficulty', 'total', 'bands', 'band'])]
final readonly class CheckTestRollResponse
{
    /**
     * @param list<CheckTestRollBandResponse> $bands
     */
    private function __construct(
        #[OA\Property(ref: new Model(type: RollResponse::class), description: 'The check\'s dice as rolled: normalized notation, total and every dice group; null for a pool check, for a progress roll, and for a burned roll (its action die is in the challenge).', nullable: true)]
        public ?RollResponse $dice,
        #[OA\Property(ref: new Model(type: CheckTestRollPoolResponse::class), description: 'The check\'s pool as rolled, or as pushed by the push endpoint; null for a dice check.', nullable: true)]
        public ?CheckTestRollPoolResponse $pool,
        #[OA\Property(ref: new Model(type: CheckTestRollPushResponse::class), description: 'The push the check declares: a pool check can be pushed when a die failed, a challenge check burns its momentum; null for a check that declares none, and for a dice check.', nullable: true)]
        public ?CheckTestRollPushResponse $push,
        #[OA\Property(ref: new Model(type: CheckTestRollChallengeResponse::class), description: 'The check\'s challenge dice as rolled, or as burned by the push endpoint (ADR 0049); null for a dice or a pool check.', nullable: true)]
        public ?CheckTestRollChallengeResponse $challenge,
        #[OA\Property(description: 'Only on a pushed or burned roll: the total the roll had before the push.', example: 1, nullable: true)]
        public ?int $rolledSuccesses,
        #[OA\Property(description: 'The special result of the roll as it stands, after a push the push\'s (ADR 0044): critical, messy critical or bestial failure, or a match of the challenge dice (ADR 0049), which a burn keeps. Null for none, and for a dice check.', nullable: true)]
        public ?CheckTestSpecialResult $special,
        #[OA\Property(description: 'The value of the check\'s modifier formula for the samples; 0 when it has none, and for a pool check.', example: 6)]
        public int $modifier,
        #[OA\Property(description: 'The situational modifier that applied.', example: 0)]
        public int $situational,
        #[OA\Property(description: 'The Check difficulty that applied: the one sent, else the declared default; null for a check that declares none.', example: 13, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'The dice, plus the modifier, plus the situational modifier; the successes of a pool plus the bonus of its critical pairs.', example: 13)]
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
            $view->dice instanceof RollView ? RollResponse::fromView($view->dice) : null,
            $view->pool instanceof CheckTestPoolView ? CheckTestRollPoolResponse::fromView($view->pool) : null,
            null === $view->pushLabel ? null : CheckTestRollPushResponse::of($view->pushLabel, $view->pushMaxDice, $view->rolledSpecial),
            $view->challenge instanceof CheckTestChallengeView ? CheckTestRollChallengeResponse::fromView($view->challenge) : null,
            $view->rolledSuccesses,
            $view->special,
            $view->modifier,
            $view->situational,
            $view->difficulty,
            $view->total,
            array_map(CheckTestRollBandResponse::fromView(...), $view->bands),
            $view->band,
        );
    }
}
