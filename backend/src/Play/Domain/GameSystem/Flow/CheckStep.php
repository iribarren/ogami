<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

use App\Formula\Domain\Formula;

/**
 * Performs a Check of the release for a Character (ADR 0024). "difficulty" fixes the Check
 * difficulty (a literal, or a Formula over Trackers read when the step completes); without it the
 * player sets it at roll time. "outcomes" branch on the band the total falls in, by band key; a band
 * without an Outcome continues with the step's own "next" (or the following step).
 *
 * The step names one check, or a check set the player picks from when rolling (ADR 0042). Every
 * check of a set has the same band keys, so the difficulty and the outcomes serve any of them.
 */
final readonly class CheckStep extends Step
{
    /**
     * @param list<Effect>           $effects
     * @param non-empty-list<string> $checks   the key of the check, or the keys of the check set (two or more) in the order the step lists them
     * @param array<string, Outcome> $outcomes the Outcome of each band that has one, by band key
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public array $checks,
        public int|Formula|null $difficulty,
        public array $outcomes,
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }

    /**
     * Whether the player picks the check to roll from several.
     */
    public function isSet(): bool
    {
        return \count($this->checks) > 1;
    }

    /**
     * The one check a single-check step rolls; null for a check set.
     */
    public function singleCheck(): ?string
    {
        return $this->isSet() ? null : $this->checks[0];
    }

    /**
     * The key of any check of the step: they share the band keys, so it answers for the whole set.
     */
    public function anyCheck(): string
    {
        return $this->checks[0];
    }

    public function outcomeFor(string $bandKey): ?Outcome
    {
        return $this->outcomes[$bandKey] ?? null;
    }
}
