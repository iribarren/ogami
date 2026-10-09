<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Branches on a Tracker's value with Outcome bands; never mandatory, never skipped.
 */
final readonly class ConditionStep extends Step
{
    /**
     * @param list<Effect> $effects
     * @param list<Band>   $bands
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public string $tracker,
        public array $bands,
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }
}
