<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\Oracle\OracleTableResult;

/**
 * A roll on an oracle table: one step per table rolled, root first.
 */
final readonly class OracleTableResultView
{
    /**
     * @param string                              $table the key of the table rolled on first
     * @param non-empty-list<OracleTableStepView> $steps
     */
    public function __construct(
        public string $table,
        public array $steps,
    ) {
    }

    public static function fromResult(string $table, OracleTableResult $result): self
    {
        return new self($table, array_map(OracleTableStepView::fromStep(...), $result->steps()));
    }
}
