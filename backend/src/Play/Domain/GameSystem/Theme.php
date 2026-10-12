<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * The curated Themes a GameSystem release may name (docs/contracts/gamesystem-release.md, "Theme").
 * Play carries the name to its client, which owns the palette; a release without one uses the
 * default app theme.
 */
enum Theme: string
{
    case Parchment = 'parchment';
    case Neon = 'neon';
    case Noir = 'noir';
    case Eldritch = 'eldritch';
    case Starship = 'starship';
}
