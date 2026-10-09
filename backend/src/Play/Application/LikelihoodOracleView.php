<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * A likelihood oracle of the campaign's pinned release: what the player picks to ask it.
 */
final readonly class LikelihoodOracleView
{
    /**
     * @param list<LikelihoodLevelView> $levels       in definition order
     * @param ?LikelihoodChaosView      $chaos        null when the oracle takes no chaos factor
     * @param ?string                   $chaosTracker the key of the Tracker whose value is the chaos factor; null when the player picks it
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $levels,
        public ?LikelihoodChaosView $chaos,
        public ?string $chaosTracker = null,
    ) {
    }
}
