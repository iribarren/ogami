<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The narrative sections of a schema version 2 draft that Studio edits item by item (ADR 0030).
 * The values are the keys of the release document.
 */
enum NarrativeSection: string
{
    case Trackers = 'trackers';
    case Tags = 'tags';
    case FactSlots = 'factSlots';
    case SceneTypes = 'sceneTypes';
    case Flows = 'flows';

    /**
     * What one item of the section is called in a message, with the glossary's spelling.
     */
    public function noun(): string
    {
        return match ($this) {
            self::Trackers => 'tracker',
            self::Tags => 'tag',
            self::FactSlots => 'fact slot',
            self::SceneTypes => 'Scene Type',
            self::Flows => 'flow',
        };
    }
}
