<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Rolls dice, with optional Outcome bands on the total.
 */
final readonly class RollStep extends Step
{
    /**
     * @param list<Effect> $effects
     * @param list<Band>   $bands
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public string $dice,
        public array $bands,
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }
}
