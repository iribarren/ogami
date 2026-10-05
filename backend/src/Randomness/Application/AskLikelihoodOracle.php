<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks a likelihood oracle a yes/no question with a likelihood level and an optional
 * chaos factor (the neutral one when null). Fails with InvalidLikelihoodOracle when
 * the oracle is invalid, the level is unknown or the chaos factor is not allowed.
 *
 * @implements Query<LikelihoodAnswerView>
 */
final readonly class AskLikelihoodOracle implements Query
{
    /**
     * @param array<mixed> $oracle     the oracle definition, as decoded JSON (see LikelihoodOracle::fromArray)
     * @param string       $likelihood the key of the likelihood level asked with
     */
    public function __construct(
        public array $oracle,
        public string $likelihood,
        public ?int $chaosFactor,
    ) {
    }
}
