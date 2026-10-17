<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The sections of a library pack that a draft imports from (ADR 0033). The values are stored and
 * sent by the API. Oracle tables and likelihood oracles share one section, as they share one key
 * namespace.
 */
enum LibrarySection: string
{
    case Oracles = 'oracles';
    case Trackers = 'trackers';
    case Tags = 'tags';
    case FactSlots = 'fact-slots';
    case SceneTypes = 'scene-types';

    /**
     * What one item of the section is called in a message, with the glossary's spelling.
     */
    public function noun(): string
    {
        return match ($this) {
            self::Oracles => 'oracle',
            self::Trackers => 'tracker',
            self::Tags => 'tag',
            self::FactSlots => 'fact slot',
            self::SceneTypes => 'Scene Type',
        };
    }

    /**
     * The lists of the release document that hold the section's items, as dotted paths, in the
     * order a pack lists them.
     *
     * @return list<string>
     */
    public function lists(): array
    {
        return match ($this) {
            self::Oracles => ['oracles.tables', 'oracles.likelihood'],
            self::Trackers => ['trackers'],
            self::Tags => ['tags'],
            self::FactSlots => ['factSlots'],
            self::SceneTypes => ['sceneTypes'],
        };
    }
}
