<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * A FlowRun guides play while active; completed after the last phase, play goes on unguided (ADR
 * 0018 decision 10). Pausing guidance comes with play-flow-run slice 13.
 */
enum FlowRunStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
}
