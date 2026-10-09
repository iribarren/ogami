<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Where a step continues and what it does for one result: a band, an oracle answer, a table
 * entry, "otherwise" or a choice option. Without "next" the step's own "next" (or the following
 * step) runs; "end" ends the part.
 */
final readonly class Outcome
{
    /**
     * @param list<Effect> $effects
     */
    public function __construct(
        public ?string $next = null,
        public array $effects = [],
    ) {
    }
}
