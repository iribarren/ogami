<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The kinds of FlowRun history entries. Skips live here, not in the journal, which is the story
 * (ADR 0017 §3). Guidance, hand edits and switches join them with pause and resume.
 */
enum FlowRunEvent: string
{
    case Skip = 'skip';
    case PhaseEnded = 'phaseEnded';
    case Completed = 'completed';
}
