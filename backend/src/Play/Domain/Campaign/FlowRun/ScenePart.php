<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The parts of a guided scene, in order: the phase's scene opening, the Scene Type's setup and play
 * steps, open play until "End scene", the Scene Type's closing and the phase's scene closing.
 */
enum ScenePart: string
{
    case SceneOpening = 'sceneOpening';
    case Setup = 'setup';
    case Play = 'play';
    case Open = 'open';
    case Closing = 'closing';
    case SceneClosing = 'sceneClosing';

    /**
     * The part after this one; null after the scene closing.
     */
    public function next(): ?self
    {
        return self::cases()[array_search($this, self::cases(), true) + 1] ?? null;
    }

    public function label(): string
    {
        return match ($this) {
            self::SceneOpening => 'Scene opening',
            self::Setup => 'Setup',
            self::Play => 'Play',
            self::Open => 'Open play',
            self::Closing => 'Closing',
            self::SceneClosing => 'Scene closing',
        };
    }
}
