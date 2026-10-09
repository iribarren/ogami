<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * An ordered list of steps with unique keys: a Scene Type part or a phase hook.
 */
final readonly class StepList
{
    /**
     * @param list<Step> $steps
     */
    public function __construct(
        public array $steps = [],
    ) {
    }

    public function step(string $key): ?Step
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }
}
