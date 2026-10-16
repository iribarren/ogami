<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Application\LikelihoodAnswerView;
use App\Randomness\Application\OracleTableResultView;

/**
 * The outcome of a test question to a likelihood oracle: its answer and, when the answer signalled
 * a random event, the roll on the oracle's random event table.
 */
final readonly class LikelihoodTestView
{
    public function __construct(
        public LikelihoodAnswerView $answer,
        public ?OracleTableResultView $randomEvent,
    ) {
    }
}
