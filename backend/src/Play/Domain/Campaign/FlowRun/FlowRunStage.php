<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * Where a FlowRun stands between and inside scenes: at the scene pick (a mandatory step at a scene
 * boundary), in a scene, or in a hook (session, phase and world turn hooks, each in its hook Scene).
 */
enum FlowRunStage: string
{
    case ScenePick = 'scenePick';
    case Scene = 'scene';
    case Hook = 'hook';
}
