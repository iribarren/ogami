<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * "once" plays each listed Scene Type of a sequence once (one scene for player or oracle);
 * "loop" repeats until an endPhase effect or "Move on".
 */
enum PhaseMode: string
{
    case Once = 'once';
    case Loop = 'loop';
}
