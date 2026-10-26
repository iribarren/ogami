<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Application\RollView;
use App\Randomness\Domain\Roll;
use App\Studio\Domain\Check\CheckTestPoolRoll;
use App\Studio\Domain\Check\CheckTestRollResult;

/**
 * What a test roll of a check produced: the dice or the pool, the modifier, the situational modifier,
 * the difficulty that applied (none for a check without one), the total (the successes of a pool),
 * every band with its bound and the key of the band the total falls in. A pool check tells the label
 * of its push, and a pushed roll the successes it had before.
 */
final readonly class CheckTestRollView
{
    /**
     * @param list<CheckTestRollBandView> $bands in band order
     */
    public function __construct(
        public ?RollView $dice,
        public int $modifier,
        public int $situational,
        public ?int $difficulty,
        public int $total,
        public array $bands,
        public string $band,
        public ?CheckTestPoolView $pool = null,
        public ?string $pushLabel = null,
        public ?int $rolledSuccesses = null,
    ) {
    }

    public static function fromResult(CheckTestRollResult $result): self
    {
        $bands = [];
        foreach ($result->bands as $index => $band) {
            $bands[] = new CheckTestRollBandView($band->key, $band->label, $result->bounds[$index]);
        }

        return new self(
            $result->roll instanceof Roll ? RollView::fromRoll($result->roll) : null,
            $result->modifier,
            $result->situational,
            $result->difficulty,
            $result->total,
            $bands,
            $result->band->key,
            $result->pool instanceof CheckTestPoolRoll ? CheckTestPoolView::fromPoolRoll($result->pool) : null,
            $result->push?->label,
            $result->rolledSuccesses,
        );
    }
}
