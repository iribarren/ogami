<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

use App\Formula\Domain\Formula;

/**
 * Performs a Check of the release for a Character (ADR 0024). "difficulty" fixes the Check
 * difficulty (a literal, or a Formula over Trackers read when the step completes); without it the
 * player sets it at roll time. "outcomes" branch on the band the total falls in, by band key; a band
 * without an Outcome continues with the step's own "next" (or the following step).
 */
final readonly class CheckStep extends Step
{
    /**
     * @param list<Effect>           $effects
     * @param array<string, Outcome> $outcomes the Outcome of each band that has one, by band key
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public string $check,
        public int|Formula|null $difficulty,
        public array $outcomes,
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }

    public function outcomeFor(string $bandKey): ?Outcome
    {
        return $this->outcomes[$bandKey] ?? null;
    }
}
