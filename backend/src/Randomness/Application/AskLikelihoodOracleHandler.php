<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\QueryHandler;

final readonly class AskLikelihoodOracleHandler implements QueryHandler
{
    public function __construct(
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws InvalidLikelihoodOracle when the oracle is invalid, the level is unknown or the chaos factor is not allowed
     */
    public function __invoke(AskLikelihoodOracle $query): LikelihoodAnswerView
    {
        return LikelihoodAnswerView::fromAnswer(
            LikelihoodOracle::fromArray($query->oracle)->ask($query->likelihood, $query->chaosFactor, $this->random),
        );
    }
}
