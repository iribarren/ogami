<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Asks a likelihood oracle, at a given level or the player's choice.
 */
final readonly class OracleStep extends Step
{
    /**
     * @param list<Effect> $effects
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public string $oracle,
        public ?string $likelihood,
        public OracleBranches $branches,
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }
}
