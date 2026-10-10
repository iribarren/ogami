<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The kinds of FlowRun history entries. Skips, guidance and hand edits live here, not in the
 * journal, which is the story (ADR 0017 §3).
 */
enum FlowRunEvent: string
{
    case Skip = 'skip';
    case TrackerEdit = 'trackerEdit';
    case SceneTypeSwitch = 'sceneTypeSwitch';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case SceneAbandoned = 'sceneAbandoned';
    case PhaseEnded = 'phaseEnded';
    case Completed = 'completed';
}
