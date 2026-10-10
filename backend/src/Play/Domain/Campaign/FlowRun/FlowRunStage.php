<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * Where a FlowRun stands between and inside scenes: at the scene pick (a mandatory step at a scene
 * boundary), or in a scene. Hook stages (session, phase and world turn hooks) come with hook Scenes.
 */
enum FlowRunStage: string
{
    case ScenePick = 'scenePick';
    case Scene = 'scene';
}
