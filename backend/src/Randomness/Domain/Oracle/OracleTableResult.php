<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * The outcome of consulting an oracle table: one step per table rolled, root first.
 */
final readonly class OracleTableResult
{
    /**
     * @param non-empty-list<OracleTableStep> $steps
     */
    public function __construct(
        private array $steps,
    ) {
    }

    /**
     * @return non-empty-list<OracleTableStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }
}
