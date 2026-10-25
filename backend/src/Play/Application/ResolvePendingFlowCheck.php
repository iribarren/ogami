<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Resolves the roll the FlowRun's current check step waits on (ADR 0041): Keep completes the step on
 * the band rolled; Push re-rolls the dice that failed, pays the cost of the push and completes the
 * step on the pushed band.
 */
final readonly class ResolvePendingFlowCheck implements Command
{
    /**
     * @param bool         $push  true pushes the roll, false keeps it
     * @param list<string> $picks with a push, the option the player picks for each mark without an option
     *                            of the cost that has an unmarked option left, in cost order
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $stepKey,
        public bool $push,
        public array $picks = [],
    ) {
    }
}
