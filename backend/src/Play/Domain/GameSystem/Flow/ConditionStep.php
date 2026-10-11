<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Branches with Outcome bands on a Tracker's value, or on the number of open NPCs or Threads it
 * counts; never mandatory, never skipped. Exactly one of the two is set.
 */
final readonly class ConditionStep extends Step
{
    /**
     * @param list<Effect> $effects
     * @param list<Band>   $bands
     *
     * @throws \InvalidArgumentException unless exactly one of $tracker and $count is set
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public ?string $tracker,
        public array $bands,
        public ?ItemCount $count = null,
    ) {
        if ((null === $tracker) === (!$count instanceof ItemCount)) {
            throw new \InvalidArgumentException('A condition step needs exactly one of a tracker and a count.');
        }

        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }
}
