<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A Scene is a scene of play, or a hook Scene that records the steps a phase runs at a boundary
 * (ADR 0018 decision 13).
 */
enum SceneKind: string
{
    case Scene = 'scene';
    case Hook = 'hook';
}
