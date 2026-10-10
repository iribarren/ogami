<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * A FlowRun guides play while active; paused, the player plays freely and resumes later; completed
 * after the last phase, play goes on unguided (ADR 0018 decision 10).
 */
enum FlowRunStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
}
