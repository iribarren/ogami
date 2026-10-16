<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * One oracle table or likelihood oracle of a draft.
 */
final readonly class OracleView
{
    /**
     * @param array<mixed> $definition the oracle as stored, release document shape
     * @param list<string> $usedBy     where the draft references it, e.g. "flows[0].phases[1].steps[2]"
     */
    public function __construct(
        public string $key,
        public array $definition,
        public array $usedBy,
    ) {
    }
}
