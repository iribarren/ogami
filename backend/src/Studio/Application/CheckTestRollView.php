<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Application\RollView;
use App\Studio\Domain\Check\CheckTestRollResult;

/**
 * What a test roll of a check produced: the dice, the modifier, the situational modifier, the
 * difficulty that applied (none for a check without one), the total, every band with its bound and
 * the key of the band the total falls in.
 */
final readonly class CheckTestRollView
{
    /**
     * @param list<CheckTestRollBandView> $bands in band order
     */
    public function __construct(
        public RollView $dice,
        public int $modifier,
        public int $situational,
        public ?int $difficulty,
        public int $total,
        public array $bands,
        public string $band,
    ) {
    }

    public static function fromResult(CheckTestRollResult $result): self
    {
        $bands = [];
        foreach ($result->bands as $index => $band) {
            $bands[] = new CheckTestRollBandView($band->key, $band->label, $result->bounds[$index]);
        }

        return new self(RollView::fromRoll($result->roll), $result->modifier, $result->situational, $result->difficulty, $result->total, $bands, $result->band->key);
    }
}
