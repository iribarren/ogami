<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Rolls the oracle table the scene pick of the guided campaign's FlowRun rolls on and picks the
 * Scene Type of the entry rolled; its scene starts. The roll is not recorded in the journal.
 */
final readonly class PickSceneTypeByOracle implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
    ) {
    }
}
