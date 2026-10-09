<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

use App\Randomness\Domain\Oracle\YesNoAnswer;

/**
 * The Outcomes of an oracle step per answer; any may be missing.
 */
final readonly class OracleBranches
{
    public function __construct(
        public ?Outcome $yes = null,
        public ?Outcome $no = null,
        public ?Outcome $exceptionalYes = null,
        public ?Outcome $exceptionalNo = null,
    ) {
    }

    /**
     * The Outcome of this answer; an exceptional answer without its own branch uses yes / no.
     */
    public function for(YesNoAnswer $answer): ?Outcome
    {
        return match ($answer) {
            YesNoAnswer::ExceptionalYes => $this->exceptionalYes ?? $this->yes,
            YesNoAnswer::Yes => $this->yes,
            YesNoAnswer::No => $this->no,
            YesNoAnswer::ExceptionalNo => $this->exceptionalNo ?? $this->no,
        };
    }
}
